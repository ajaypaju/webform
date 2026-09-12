// Submission transport, DOM-free: UUIDv7 ids, retry with backoff + jitter, Retry-After respected. render.js owns
// storage and the page; this owns "how many times and how long".

/** RFC 9562 v7: 48-bit ms timestamp, version nibble, variant bits, 74 random bits. */
export function uuidv7(now = Date.now(), random = (b) => crypto.getRandomValues(b)) {
  const bytes = random(new Uint8Array(16));
  const ms = BigInt(now);

  for (let i = 0; i < 6; i++) bytes[i] = Number((ms >> BigInt(40 - 8 * i)) & 0xffn);
  bytes[6] = (bytes[6] & 0x0f) | 0x70;
  bytes[8] = (bytes[8] & 0x3f) | 0x80;

  const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export const RETRYABLE = new Set([429, 503, 502, 504, 0]);

/** Milliseconds to wait before attempt n (1-based): max(Retry-After, 1s·2^(n-1)) capped at 30 s, plus up to 25% jitter. */
export function retryDelay(attempt, retryAfterSeconds = null, rand = Math.random) {
  const backoff = Math.min(30_000, 1000 * 2 ** (attempt - 1));
  const base = Math.max(backoff, (retryAfterSeconds ?? 0) * 1000);

  return Math.round(base * (1 + rand() * 0.25));
}

/**
 * POST until the server gives a final answer. 2xx/404/409/413/422 are final; 429/5xx/network errors are retried.
 * @returns {Promise<{status: number, body: any, attempts: number}>} status 0 = gave up on network errors
 */
export async function submitWithRetry(url, body, { fetchFn = fetch, sleep = (ms) => new Promise((r) => setTimeout(r, ms)), maxAttempts = 8, onRetry = () => {} } = {}) {
  let last = { status: 0, body: null };

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    last = await post(fetchFn, url, body);

    if (!RETRYABLE.has(last.status)) return { ...last, attempts: attempt };
    if (attempt === maxAttempts) break;

    const delay = retryDelay(attempt, last.retryAfter);
    onRetry({ attempt, status: last.status, delay });
    await sleep(delay);
  }

  return { ...last, attempts: maxAttempts };
}

async function post(fetchFn, url, body) {
  try {
    const response = await fetchFn(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(body),
    });
    const text = await response.text();
    let parsed = null;

    try {
      parsed = text === '' ? null : JSON.parse(text);
    } catch {
      parsed = null;
    }

    return { status: response.status, body: parsed, retryAfter: Number(response.headers.get('Retry-After')) || null };
  } catch {
    return { status: 0, body: null, retryAfter: null };
  }
}
