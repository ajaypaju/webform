// Pattern checks via a Web Worker with a time budget. On timeout, error, or no Worker support the check is skipped
// (null): the field submits and the server decides. One console.warn per pattern.
const TIMEOUT_MS = 50;

let worker = null;
let seq = 0;
const pending = new Map();
const warned = new Set();

function skip(pattern, why) {
  if (!warned.has(pattern)) {
    warned.add(pattern);
    console.warn(`Pattern check skipped (${why}); the server will validate it: ${pattern}`);
  }

  return null;
}

function settleAll(ok) {
  for (const entry of pending.values()) entry.resolve(ok);
  pending.clear();
}

function getWorker() {
  if (worker === null) {
    worker = new Worker(new URL('./pattern-worker.js', import.meta.url), { type: 'module' });
    worker.onmessage = ({ data }) => pending.get(data.id)?.resolve(data.ok);
    worker.onerror = () => settleAll(null);
  }

  return worker;
}

/** @returns {Promise<boolean|null>} */
export function matchInWorker(pattern, value) {
  return new Promise((resolve) => {
    let w;

    try {
      w = getWorker();
    } catch (e) {
      return resolve(skip(pattern, e.message));
    }

    const id = ++seq;
    const timer = setTimeout(() => {
      // WHY: a stuck worker never answers; terminate it so the next check starts clean.
      pending.delete(id);
      w.terminate();
      worker = null;
      settleAll(null);
      resolve(skip(pattern, `no answer within ${TIMEOUT_MS} ms`));
    }, TIMEOUT_MS);

    pending.set(id, {
      resolve: (ok) => {
        clearTimeout(timer);
        pending.delete(id);
        resolve(ok);
      },
    });

    w.postMessage({ id, pattern, value });
  });
}

/**
 * Pre-computes every pattern check the validator will ask for, so validate() can stay synchronous.
 * @returns {Promise<(pattern: string, value: string) => boolean|null>}
 */
export async function patternMatcher(fields, values) {
  const results = new Map();
  const jobs = [];

  for (const field of fields) {
    const pattern = field.rules?.pattern;
    const value = values[field.id];

    if (field.type === 'text' && typeof pattern === 'string' && typeof value === 'string') {
      jobs.push(matchInWorker(pattern, value).then((ok) => results.set(`${pattern} ${value}`, ok)));
    }
  }

  await Promise.all(jobs);

  return (pattern, value) => results.get(`${pattern} ${value}`) ?? null;
}
