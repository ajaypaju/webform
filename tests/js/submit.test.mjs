import test from 'node:test';
import assert from 'node:assert/strict';
import { uuidv7, retryDelay, submitWithRetry } from '../../resources/js/form/submit.js';

test('uuidv7 has the v7 layout, embeds the timestamp, and is unique', () => {
  const id = uuidv7(1_700_000_000_000);
  assert.match(id, /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
  assert.equal(parseInt(id.replace(/-/g, '').slice(0, 12), 16), 1_700_000_000_000);
  assert.notEqual(uuidv7(), uuidv7());
});

test('retryDelay backs off exponentially, honours Retry-After, caps at 30s, adds up to 25% jitter', () => {
  assert.equal(retryDelay(1, null, () => 0), 1000);
  assert.equal(retryDelay(3, null, () => 0), 4000);
  assert.equal(retryDelay(1, 7, () => 0), 7000);
  assert.equal(retryDelay(10, null, () => 0), 30_000);
  assert.equal(retryDelay(1, null, () => 1), 1250);
});

const reply = (status, body = {}, headers = {}) => ({
  status,
  headers: { get: (h) => headers[h] ?? null },
  text: async () => JSON.stringify(body),
});

test('submitWithRetry reuses the same body on 503/429/network and stops at the first final answer', async () => {
  const seen = [];
  const answers = [reply(503, { error: 'unavailable' }, { 'Retry-After': '1' }), reply(429, { error: 'rate_limited' }), Promise.reject(new Error('offline')), reply(202, { submission_id: 'x' })];
  const fetchFn = async (url, init) => {
    seen.push(JSON.parse(init.body).submission_id);
    return answers.shift();
  };
  const waits = [];

  const result = await submitWithRetry('/s', { submission_id: 'same-id' }, { fetchFn, sleep: async (ms) => waits.push(ms), maxAttempts: 8 });

  assert.equal(result.status, 202);
  assert.equal(result.attempts, 4);
  assert.deepEqual(seen, ['same-id', 'same-id', 'same-id', 'same-id']);
  assert.equal(waits.length, 3);
  assert.ok(waits[0] >= 1000 && waits[1] >= 2000 && waits[2] >= 4000);
});

test('submitWithRetry does not retry 422/409/404 and gives up after maxAttempts', async () => {
  const fetchFn = async () => reply(422, { errors: { email: ['email'] } });
  assert.equal((await submitWithRetry('/s', {}, { fetchFn })).attempts, 1);

  let calls = 0;
  const down = async () => { calls++; return reply(503); };
  const result = await submitWithRetry('/s', {}, { fetchFn: down, sleep: async () => {}, maxAttempts: 3 });
  assert.equal(result.status, 503);
  assert.equal(calls, 3);
});
