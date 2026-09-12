import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { validate } from '../../resources/js/form/validate.js';

// PHP/JS parity: every submissions case, exact valid/errors/output — except redos_*, which is skipped: V8 has no
// backtrack limit in u-mode (its linear fallback engine can't do u-mode), so the case would run ~20 s. Server-side
// protection is proven by the PHP test (SafePattern, I8); in the browser render.js runs patterns in a Web Worker
// with a 50 ms budget and skips the check on timeout.
const dir = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'conformance', 'submissions');

for (const file of readdirSync(dir).sort()) {
  const cases = JSON.parse(readFileSync(join(dir, file), 'utf8'));

  test(`submissions/${file}: ${cases.length} cases`, () => {
    for (const c of cases) {
      if (c.name.startsWith('redos_')) continue;

      const result = validate(structuredClone(c.definition), structuredClone(c.input));

      assert.deepEqual(result, { valid: c.valid, errors: c.errors, data: c.output }, `${file} › ${c.name}`);
    }
  });
}
