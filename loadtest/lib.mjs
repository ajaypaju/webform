// Shared helpers for burst.mjs and reconcile.mjs. No dependencies.
import { execFileSync } from 'node:child_process';

export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** --flag value / --flag (boolean) parser. */
export function args(defaults) {
  const out = { ...defaults };
  const argv = process.argv.slice(2);

  for (let i = 0; i < argv.length; i++) {
    const m = /^--([a-z][a-z0-9-]*)(?:=(.*))?$/.exec(argv[i]);
    if (!m) continue;
    const key = m[1].replace(/-([a-z])/g, (_, c) => c.toUpperCase());
    let value = m[2];
    if (value === undefined && argv[i + 1] !== undefined && !argv[i + 1].startsWith('--')) value = argv[++i];
    if (value === undefined) value = true;
    out[key] = typeof defaults[key] === 'number' ? Number(value) : value;
  }

  return out;
}

/** RFC 9562 v7 */
export function uuidv7() {
  const b = crypto.getRandomValues(new Uint8Array(16));
  const ms = BigInt(Date.now());
  for (let i = 0; i < 6; i++) b[i] = Number((ms >> BigInt(40 - 8 * i)) & 0xffn);
  b[6] = (b[6] & 0x0f) | 0x70;
  b[8] = (b[8] & 0x3f) | 0x80;
  const h = [...b].map((x) => x.toString(16).padStart(2, '0')).join('');
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

/** Percentiles from a fixed-resolution histogram (index = ms, capped). */
export class Histogram {
  constructor(maxMs = 120_000) {
    this.counts = new Uint32Array(maxMs + 1);
    this.n = 0;
    this.max = 0;
  }

  add(ms) {
    const i = Math.min(this.counts.length - 1, Math.max(0, Math.round(ms)));
    this.counts[i]++;
    this.n++;
    if (ms > this.max) this.max = ms;
  }

  percentile(p) {
    if (this.n === 0) return null;
    const target = Math.ceil((p / 100) * this.n);
    let seen = 0;
    for (let i = 0; i < this.counts.length; i++) {
      seen += this.counts[i];
      if (seen >= target) return i;
    }
    return this.counts.length - 1;
  }

  summary() {
    return { n: this.n, p50: this.percentile(50), p95: this.percentile(95), p99: this.percentile(99), max: Math.round(this.max) };
  }
}

const project = process.env.COMPOSE_PROJECT_NAME || 'webform';

/** Run a command inside one of the stack's containers through the docker socket. */
export function inContainer(service, cmd, { input } = {}) {
  return execFileSync('docker', ['exec', '-i', `${project}-${service}-1`, ...cmd], { input, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], maxBuffer: 512 * 1024 * 1024 });
}

/** Total consumer-group lag over the submissions topic, via rpk; null when the group is unknown. */
export function consumerLag(group) {
  let out;
  try {
    out = inContainer('redpanda', ['rpk', 'group', 'describe', group]);
  } catch {
    return null;
  }
  // rpk prints a summary block first; TOTAL-LAG is the sum over partitions.
  const m = /^TOTAL-LAG\s+(\d+)/m.exec(out);
  return m ? Number(m[1]) : null;
}
