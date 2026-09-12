// Arrival-rate driven load generator for POST /v1/forms/{form}/submissions.
// Each request gets a fresh UUIDv7, retried with backoff on 429/503/network with the SAME id (I2), and counts as
// acked only on 202 (I1). Results stream to a JSONL file; only histograms and per-second counters stay in memory.
import { createWriteStream } from 'node:fs';
import { mkdir } from 'node:fs/promises';
import { once } from 'node:events';
import { args, sleep, uuidv7, Histogram } from './lib.mjs';

const opts = args({
  ingest: process.env.INGEST_URL || 'http://localhost:8080',
  api: process.env.API_URL || 'http://localhost:8000',
  key: process.env.LOAD_API_KEY || '',
  form: '',
  steady: 20,
  spike: 2000,
  spikeSecs: 60,
  ramp: 10,
  pre: 10,
  post: 20,
  workers: 2048,
  maxAttempts: 6,
  timeoutMs: 15_000,
  out: '',
  bypassIpLimit: false,
  tag: '',
});

const DEFINITION = { fields: [
  { id: 'name', type: 'text', label: 'Name', required: true, rules: { min_length: 2, max_length: 80 } },
  { id: 'email', type: 'email', label: 'Email', required: true },
  { id: 'plan', type: 'select', label: 'Plan', required: true, options: [{ value: 'free', label: 'Free' }, { value: 'pro', label: 'Pro' }] },
  { id: 'seats', type: 'number', label: 'Seats', required: false, rules: { integer: true, min: 1 }, visible_if: { field: 'plan', op: 'eq', value: 'pro' } },
  { id: 'consent', type: 'checkbox', label: 'Consent', required: true },
] };

// --- setup --------------------------------------------------------------------------------------

async function api(method, path, body) {
  const res = await fetch(`${opts.api}${path}`, {
    method, headers: { Authorization: `Bearer ${opts.key}`, 'Content-Type': 'application/json', Accept: 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${await res.text()}`);
  return res.json();
}

async function setupForm() {
  if (opts.form) return opts.form;
  if (!opts.key) throw new Error('need --form <id> or --key <api key> (LOAD_API_KEY) to create one');
  const form = await api('POST', '/v1/forms', { name: `loadtest ${new Date().toISOString()}`, definition: DEFINITION });
  await api('POST', `/v1/forms/${form.id}/publish`);
  return form.id;
}

async function renderToken(form) {
  const html = await (await fetch(`${opts.ingest}/f/${form}`)).text();
  const version = /data-version-id="([^"]+)"/.exec(html)?.[1];
  const token = /data-render-token="([^"]+)"/.exec(html)?.[1];
  if (!version || !token) throw new Error(`could not read version/token from ${opts.ingest}/f/${form}`);
  return { version, token };
}

// --- schedule -----------------------------------------------------------------------------------

/** Target rate at second t of the run. */
function targetRate(t) {
  const { steady, spike, spikeSecs, ramp, pre } = opts;
  if (t < pre) return steady;
  if (t < pre + ramp) return steady + ((spike - steady) * (t - pre)) / ramp;
  if (t < pre + ramp + spikeSecs) return spike;
  if (t < pre + ramp + spikeSecs + ramp) return spike - ((spike - steady) * (t - pre - ramp - spikeSecs)) / ramp;
  return steady;
}

const runSeconds = opts.pre + opts.ramp + opts.spikeSecs + opts.ramp + opts.post;

// --- one request --------------------------------------------------------------------------------

const stats = {
  sent: 0, acked: 0, s429: 0, s503: 0, s422: 0, other: 0, network: 0, retries: 0, gaveUp: 0,
  ack: new Histogram(), queue: new Histogram(),
  // Indexed by the second a request was actually dispatched (or, for target, scheduled); the run can outlast
  // its schedule when workers sit in retry sleeps, so the array grows on demand.
  perSecond: [],
};

const slot = (second) => (stats.perSecond[second] ??= { target: 0, sent: 0, acked: 0 });

let synthetic = 0;

async function attempt(url, body, headers) {
  try {
    const res = await fetch(url, { method: 'POST', headers, body, signal: AbortSignal.timeout(opts.timeoutMs) });
    await res.arrayBuffer();
    return { status: res.status, retryAfter: Number(res.headers.get('Retry-After')) || 0 };
  } catch {
    return { status: 0, retryAfter: 0 };
  }
}

async function submit(job) {
  const id = uuidv7();
  const body = JSON.stringify({
    submission_id: id, form_version_id: job.version, render_token: job.token,
    data: { name: 'Load Test', email: `load+${id.slice(0, 8)}@example.com`, plan: 'free', consent: true }, honeypot: '',
  });
  const headers = { 'Content-Type': 'application/json', Accept: 'application/json', 'User-Agent': 'webform-loadtest' };
  if (opts.bypassIpLimit) {
    synthetic = (synthetic + 1) % 65_000;
    headers['X-Forwarded-For'] = `10.200.${Math.floor(synthetic / 250)}.${(synthetic % 250) + 1}`;
  }

  const started = performance.now();
  let status = 0;
  let attempts = 0;

  for (attempts = 1; attempts <= opts.maxAttempts; attempts++) {
    const r = await attempt(`${opts.ingest}/v1/forms/${job.form}/submissions`, body, headers);
    status = r.status;
    if (status === 202 || status === 422 || status === 404 || status === 409 || status === 413) break;
    if (status === 429) stats.s429++;
    else if (status === 503) stats.s503++;
    else if (status === 0) stats.network++;
    else stats.other++;
    if (attempts === opts.maxAttempts) { stats.gaveUp++; break; }
    stats.retries++;
    const backoff = Math.min(10_000, 200 * 2 ** (attempts - 1));
    await sleep(Math.max(backoff, r.retryAfter * 1000) * (1 + Math.random() * 0.25));
  }

  const ackMs = performance.now() - started;
  stats.sent++;
  slot(Math.floor((job.startedAt - t0) / 1000)).sent++;

  if (status === 202) {
    stats.acked++;
    slot(Math.floor((performance.now() - t0) / 1000)).acked++;
    stats.ack.add(ackMs);
  } else if (status === 422) stats.s422++;
  else if (status !== 429 && status !== 503 && status !== 0) stats.other++;

  return { id, status, attempts, ack_ms: Math.round(ackMs), queue_ms: Math.round(job.startedAt - job.at), t: Math.round(job.at - t0) };
}

// --- main ---------------------------------------------------------------------------------------

const form = await setupForm();
const { version, token } = await renderToken(form);
console.log(`form ${form} version ${version}; waiting 2.5s for the minimum fill time`);
await sleep(2500);

await mkdir(new URL('./out/', import.meta.url), { recursive: true });
const runId = `${new Date().toISOString().replace(/[:.]/g, '-')}${opts.tag ? '-' + opts.tag : ''}`;
const outPath = opts.out || new URL(`./out/${runId}.jsonl`, import.meta.url).pathname;
const out = createWriteStream(outPath, { flags: 'a' });
const write = async (line) => { if (!out.write(line + '\n')) await once(out, 'drain'); };

const queue = [];
let active = 0;
let done = 0;
let scheduled = 0;
let maxQueue = 0;
const t0 = performance.now();

function pump() {
  while (active < opts.workers && queue.length > 0) {
    const job = queue.shift();
    job.startedAt = performance.now();
    stats.queue.add(job.startedAt - job.at);
    active++;
    submit(job).then(async (result) => { await write(JSON.stringify(result)); done++; active--; pump(); });
  }
}

console.log(`schedule: pre ${opts.pre}s @${opts.steady}/s, ramp ${opts.ramp}s, spike ${opts.spikeSecs}s @${opts.spike}/s, ramp ${opts.ramp}s, post ${opts.post}s @${opts.steady}/s; workers ${opts.workers}; bypass-ip-limit ${opts.bypassIpLimit}`);
console.log(`results: ${outPath}`);

// Scheduler: every 50 ms enqueue the requests due in that slice; fractional carry keeps the average exact.
let carry = 0;
for (let tick = 0; ; tick++) {
  const elapsed = performance.now() - t0;
  const t = elapsed / 1000;
  if (t >= runSeconds) break;
  const due = targetRate(t) * 0.05 + carry;
  const n = Math.floor(due);
  carry = due - n;
  const second = Math.floor(t);
  slot(second).target += n;
  for (let i = 0; i < n; i++) queue.push({ form, version, token, at: performance.now() });
  scheduled += n;
  if (queue.length > maxQueue) maxQueue = queue.length;
  pump();
  if (tick % 20 === 0) {
    process.stdout.write(`\r t=${second.toString().padStart(3)}s target=${targetRate(t).toFixed(0).padStart(5)}/s sent=${stats.sent} acked=${stats.acked} 429=${stats.s429} 503=${stats.s503} in-flight=${active} queued=${queue.length}   `);
  }
  await sleep(Math.max(0, t0 + (tick + 1) * 50 - performance.now()));
}

while (active > 0 || queue.length > 0) {
  pump();
  if (Math.floor((performance.now() - t0) / 1000) % 5 === 0) process.stdout.write(`\r draining: in-flight=${active} queued=${queue.length} acked=${stats.acked}          `);
  await sleep(100);
}
const wallSeconds = (performance.now() - t0) / 1000;
out.end();
await once(out, 'finish');
process.stdout.write('\n');

// --- report -------------------------------------------------------------------------------------

const ack = stats.ack.summary();
const q = stats.queue.summary();
const achieved = Array.from({ length: Math.ceil(wallSeconds) }, (_, i) => ({ t: i, ...slot(i) }));
const spikeWindow = achieved.slice(opts.pre + opts.ramp, opts.pre + opts.ramp + opts.spikeSecs);
const avg = (xs, k) => (xs.length ? xs.reduce((a, x) => a + x[k], 0) / xs.length : 0);

const summary = {
  run: runId, form, version, options: opts, seconds: runSeconds, wall_seconds: Math.round(wallSeconds), scheduled,
  sent: stats.sent, acked: stats.acked, refused: stats.sent - stats.acked, s429: stats.s429, s503: stats.s503, s422: stats.s422,
  network_errors: stats.network, other_errors: stats.other, retries: stats.retries, gave_up: stats.gaveUp,
  ack_ms: ack, queue_ms: q, max_queue: maxQueue,
  spike: { target_rps: opts.spike, sent_rps: avg(spikeWindow, 'sent'), acked_rps: avg(spikeWindow, 'acked') },
  per_second: achieved,
};
const summaryPath = outPath.replace(/\.jsonl$/, '.summary.json');
await (await import('node:fs/promises')).writeFile(summaryPath, JSON.stringify(summary, null, 2));

console.log(`
sent ${summary.sent}  acked ${summary.acked}  refused ${summary.refused} (429: ${summary.s429} responses, 503: ${summary.s503}, network: ${summary.network_errors}, 422: ${summary.s422}, other: ${summary.other_errors}; retries ${summary.retries}, gave up ${summary.gave_up})
ack latency ms (incl. retries)  p50 ${ack.p50}  p95 ${ack.p95}  p99 ${ack.p99}  max ${ack.max}
schedule ${runSeconds}s, wall ${Math.round(wallSeconds)}s${wallSeconds > runSeconds * 1.2 ? '  <-- retries kept workers busy well past the schedule' : ''}
spike window (by dispatch time)  target ${opts.spike}/s  dispatched ${summary.spike.sent_rps.toFixed(0)}/s  acked ${summary.spike.acked_rps.toFixed(0)}/s
generator queueing delay ms  p50 ${q.p50}  p95 ${q.p95}  p99 ${q.p99}  max ${q.max}  (max queue ${maxQueue}${q.p95 > 100 ? '  <-- generator was a bottleneck, raise --workers or lower --spike' : ''})
summary: ${summaryPath}`);
