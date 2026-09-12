import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { validate } from '../../resources/js/form/validate.js';

// PHP/JS parity: every submissions case, exact valid/errors/output.
// The redos_* case gives the same result as PHP but takes seconds: JS has no backtrack limit, and V8's linear
// fallback engine (--enable-experimental-regexp-engine-on-excessive-backtracks) does not support u-mode, which we
// need for \p{..}. Documented in conformance/README.md; the server is authoritative.
const dir = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'conformance', 'submissions');

for (const file of readdirSync(dir).sort()) {
  const cases = JSON.parse(readFileSync(join(dir, file), 'utf8'));

  test(`submissions/${file}: ${cases.length} cases`, () => {
    for (const c of cases) {
      const started = performance.now();
      const result = validate(structuredClone(c.definition), structuredClone(c.input));

      assert.deepEqual(result, { valid: c.valid, errors: c.errors, data: c.output }, `${file} › ${c.name}`);

      if (c.name.startsWith('redos_')) console.log(`# ${c.name}: ${(performance.now() - started).toFixed(0)} ms (no backtrack limit in JS)`);
    }
  });
}
