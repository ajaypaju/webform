// Reconciliation: every acked id must be a row (the claim), every row must be an id we sent, no id twice.
// Reads the run's JSONL, waits for the consumer to drain, then compares against PostgreSQL.
// Postgres is read as webform_api (SELECT-only on submissions, tenant-scoped) via psql in the postgres container.
import { readdirSync, createReadStream } from 'node:fs';
import { createInterface } from 'node:readline';
import { args, sleep, inContainer, consumerLag } from './lib.mjs';

const opts = args({ run: '', form: '', group: process.env.KAFKA_CONSUMER_GROUP || 'submissions-consumer', drainTimeout: 300, out: new URL('./out/', import.meta.url).pathname });

// --- which run ----------------------------------------------------------------------------------

function latestRun() {
  const files = readdirSync(opts.out).filter((f) => f.endsWith('.jsonl')).sort();
  if (files.length === 0) throw new Error(`no runs in ${opts.out}`);
  return `${opts.out}/${files.at(-1)}`;
}

const runPath = opts.run || latestRun();
const summary = JSON.parse(await (await import('node:fs/promises')).readFile(runPath.replace(/\.jsonl$/, '.summary.json'), 'utf8'));
const form = opts.form || summary.form;

// --- wait for the consumer ----------------------------------------------------------------------

const drainSamples = [];
const drainStart = Date.now();
let lag = consumerLag(opts.group);

while (lag === null || lag > 0) {
  if ((Date.now() - drainStart) / 1000 > opts.drainTimeout) {
    console.error(`consumer lag still ${lag} after ${opts.drainTimeout}s`);
    process.exit(2);
  }
  drainSamples.push({ t: (Date.now() - drainStart) / 1000, lag: lag ?? -1 });
  process.stdout.write(`\r waiting for consumer: lag ${lag ?? 'unknown'}   `);
  await sleep(1000);
  lag = consumerLag(opts.group);
}
process.stdout.write('\r');

// Observed drain rate: lag consumed per second while the backlog was shrinking.
let drainRate = null;
const shrinking = drainSamples.filter((s, i) => i > 0 && s.lag >= 0 && s.lag < drainSamples[i - 1].lag);
if (shrinking.length > 0) {
  const first = drainSamples[drainSamples.indexOf(shrinking[0]) - 1];
  const last = shrinking.at(-1);
  drainRate = (first.lag - last.lag) / (last.t - first.t);
}

// --- the run's ids ------------------------------------------------------------------------------

const sent = new Set();
const acked = new Set();
const refused = new Map();
const rl = createInterface({ input: createReadStream(runPath) });
for await (const line of rl) {
  if (!line) continue;
  const r = JSON.parse(line);
  sent.add(r.id);
  if (r.status === 202) acked.add(r.id);
  else refused.set(r.status, (refused.get(r.status) ?? 0) + 1);
}

// --- the stored ids -----------------------------------------------------------------------------

const tenant = inContainer('postgres', ['psql', '-U', 'webform_ingest', '-d', 'webform', '-Atc', `select tenant_id from forms where id = '${form}'`]).trim();
if (!/^[0-9a-f-]{36}$/.test(tenant)) throw new Error(`form ${form} is not a published form (ingest cannot see it)`);

const sql = `begin; select set_config('app.tenant_id', '${tenant}', true); select id || ' ' || count(*) from submissions where form_id = '${form}' group by id; commit;`;
const rows = inContainer('postgres', ['psql', '-U', 'webform_api', '-d', 'webform', '-At'], { input: sql });
const stored = new Map();
for (const line of rows.split('\n')) {
  const m = /^([0-9a-f-]{36}) (\d+)$/.exec(line.trim());
  if (m) stored.set(m[1], Number(m[2]));
}

// --- compare ------------------------------------------------------------------------------------

const missing = [...acked].filter((id) => !stored.has(id));
const phantoms = [...stored.keys()].filter((id) => !sent.has(id));
const duplicates = [...stored].filter(([, n]) => n > 1);
const refusedStored = [...sent].filter((id) => !acked.has(id) && stored.has(id));

const line = (label, value, ok) => console.log(`${label.padEnd(34)} ${String(value).padStart(8)}  ${ok === undefined ? '' : ok ? 'OK' : 'VIOLATION'}`);
console.log(`run ${runPath.split('/').pop()}  form ${form}`);
line('sent (unique ids)', sent.size);
line('acked (202)', acked.size);
line('sent but not acked (refused)', sent.size - acked.size);
for (const [status, n] of [...refused].sort()) line(`  final status ${status || 'network'}`, n);
line('stored rows for this form', [...stored.values()].reduce((a, b) => a + b, 0));
line('acked but missing', missing.length, missing.length === 0);
line('stored but never sent (phantoms)', phantoms.length, phantoms.length === 0);
line('ids stored more than once', duplicates.length, duplicates.length === 0);
line('refused but stored anyway', refusedStored.length);
console.log(`consumer drain: waited ${drainSamples.length}s${drainRate !== null ? `, observed drain rate ${drainRate.toFixed(0)} rows/s while the backlog shrank` : ', no backlog observed'}`);

if (missing.length) console.log('missing ids (first 10):', missing.slice(0, 10).join(', '));
if (phantoms.length) console.log('phantom ids (first 10):', phantoms.slice(0, 10).join(', '));

await (await import('node:fs/promises')).writeFile(runPath.replace(/\.jsonl$/, '.reconcile.json'), JSON.stringify({
  form, sent: sent.size, acked: acked.size, refused: Object.fromEntries(refused), stored: stored.size, missing: missing.length, phantoms: phantoms.length,
  duplicates: duplicates.length, refused_but_stored: refusedStored.length, drain_seconds: drainSamples.length, drain_rate_rows_per_s: drainRate, drain_samples: drainSamples,
}, null, 2));

process.exit(missing.length || phantoms.length || duplicates.length ? 1 : 0);
