# Conformance fixtures
Shared truth for the PHP validator (`App\Forms`, pure PHP) and the JS validator (`resources/js`): both must pass every
case. Errors are codes, never messages. Files are arrays of cases grouped by concern; case names are unique per file.

## submissions/*.json — `SubmissionValidator::validate(definition, input)`
`{"name", "definition", "input", "valid", "errors": {"field_id": ["code"]}, "output": {...}}`
- `output` is exactly what gets stored: validated, visible fields only, keys in definition order. `{}` when invalid.
- Strict JSON types: number = JSON number, checkbox = boolean, multiselect = array of strings, everything else = string.
- Strings are trimmed first using JS `String.prototype.trim`'s set: U+0009–U+000D, U+0020, U+00A0, U+1680,
  U+2000–U+200A, U+2028, U+2029, U+202F, U+205F, U+3000, U+FEFF. Nothing else (U+200B is a character).
- `null`, `""`, whitespace-only, and `[]` mean "not provided": dropped from output, `required` if the field requires it.
- Lengths (`min_length`, `max_length`, the 5000-character default cap for text) count Unicode code points, not bytes or UTF-16 units.
- `email`: exactly one `@`, non-empty local part, no whitespace, domain contains `.`, ≤ 254 code points. Not RFC.
- Whole-number floats with |value| ≤ 2^53−1 (JS `MAX_SAFE_INTEGER`) are stored as ints (`5.0` → `5`; JS can't tell them
  apart); larger ones stay floats. `integer`: the stored value must be an int.
- `option`: value must be one of the option values (case-sensitive, after trim); multiselect values must also be distinct.
- `date`: `YYYY-MM-DD` and a real calendar date. `min`/`max` compare as dates.
- `pattern`: search semantics, never implicitly anchored (`b` matches `abc`); authors write `^…$`. A match that hits the
  backtrack limit fails with `pattern` (I8). Cases named `redos_*` must also complete in under 500 ms in PHP.
- Visibility first (I6): a hidden field is never required, gets no rules, and its value is dropped — so it also counts
  as "not provided" for any later `visible_if` that references it. `eq`/`neq`/`in` compare strictly.
- Unknown field ids → `unknown_field` (I7); ids are case-sensitive. Codes: `required type min_length max_length pattern email min max integer option min_selected max_selected date unknown_field`.

## definitions/*.json — `DefinitionRules::validate(definition)`
`{"name", "definition", "valid", "errors": ["code"]}` — one code per violation, in field order.
Codes: `field_id` (I5: `^[a-z0-9_]{1,40}$`), `duplicate_id`, `field_type`, `visible_if_field`, `visible_if_order` (I6: earlier
fields only), `visible_if_op`, `visible_if_value`, `options_missing`, `options_forbidden`, `option_value`, `duplicate_option`,
`pattern_invalid` (does not compile), `rule_forbidden` (rule not defined for the type), `rule_value`, `min_max`, and
`pattern_unsafe` (I8 + PCRE/JS-u-mode portability): backreferences, lookaround, atomic groups `(?>`, possessive `++ *+ ?+`,
inline flags `(?i)`, POSIX classes `[[:x:]]`, `(?P<`, lone `{ } ]`, `{,n}`, `[]`, `[^]`, octal (`\0` + digit), `\p{..}` other than
the short General_Category names (`L Lu Ll Lt Lm Lo M N Nd Nl No P S Z`), and any escape outside `\d \D \w \W \s \S \b \B \n \r
\t \f \0 \cX \xHH` plus escaped `^ $ \ . * + ? ( ) [ ] { } | /` (`\-` only inside a class). `patterns/portability.json`
lists `{pattern, accept}` cases; `tests/js/patterns.test.mjs` proves every accepted pattern compiles in JS with the `u` flag.

## Known semantic gaps (documented, not fixed; the server is authoritative, so the worst case is the client disagreeing
on exotic input — never bad stored data)
- `\s`: JS matches Unicode spaces (U+00A0, U+2028, U+3000 …); PCRE without UCP matches ASCII whitespace only.
- `.`: neither matches `\n`; JS also excludes `\r`, U+2028 and U+2029, PCRE (with `u`) matches them.
- No backtrack limit in JS: a pattern PHP cuts off with `pattern` gives the same answer in the browser but may take seconds
  (`^(a+)+$` on 31 chars ≈ 20 s in V8). V8's linear fallback engine cannot be used because it does not support the `u` flag.
- `{}` as a value reads as "not provided" on both sides: PHP cannot tell `{}` from `[]`, so JS mirrors it.

## publish/*.json — `PublishCompat::check(prior_versions, draft)` (I5)
`{"name", "prior_versions": [definition…], "draft", "valid", "errors": [{"field", "code"}]}` — a field id that appeared in any
prior version keeps its type forever, even if deleted and re-added; label, help text, rules, options, required and
`visible_if` may change. Code: `type_changed`. Errors in draft field order. Ids are case-sensitive.
