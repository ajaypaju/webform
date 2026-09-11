import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const conformance = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'conformance');
const read = (relative) => JSON.parse(readFileSync(join(conformance, relative), 'utf8'));

// WHY: form.js compiles customer patterns with the u flag (\p{..}, code-point "."), and u-mode is stricter than
// PCRE. DefinitionRules is the gatekeeper, so everything it accepts must compile here.
function compiles(pattern) {
  try {
    new RegExp(pattern, 'u');
    return true;
  } catch {
    return false;
  }
}

test('every portability pattern PHP accepts compiles in JS u-mode', () => {
  const accepted = read('patterns/portability.json').filter((c) => c.accept);
  assert.ok(accepted.length > 0);

  for (const { pattern } of accepted) {
    assert.ok(compiles(pattern), `${JSON.stringify(pattern)} must compile with the u flag`);
  }
});

test('every pattern in a PHP-accepted conformance definition compiles in JS u-mode', () => {
  const patterns = new Set();

  for (const group of ['submissions', 'definitions']) {
    for (const file of readdirSync(join(conformance, group))) {
      for (const c of read(join(group, file))) {
        // A definition case rejected for its pattern is the one kind PHP did not accept.
        if (group === 'definitions' && c.errors.some((e) => e === 'pattern_unsafe' || e === 'pattern_invalid')) continue;

        for (const field of c.definition.fields) {
          if (typeof field.rules?.pattern === 'string') patterns.add(field.rules.pattern);
        }
      }
    }
  }

  assert.ok(patterns.size > 0);

  for (const pattern of patterns) {
    assert.ok(compiles(pattern), `${JSON.stringify(pattern)} must compile with the u flag`);
  }
});
