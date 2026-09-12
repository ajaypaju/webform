// Pure port of App\Forms\{Normalizer,Visibility,FieldChecks,SubmissionValidator}. Same codes, same output.
// Both sides are checked against conformance/submissions/*.json (tests/js/validate.test.mjs). No DOM here.

// JS String.prototype.trim's set; PHP reproduces exactly this list.
const WHITESPACE = '\\u0009-\\u000D\\u0020\\u00A0\\u1680\\u2000-\\u200A\\u2028\\u2029\\u202F\\u205F\\u3000\\uFEFF';
const TRIM = new RegExp(`^[${WHITESPACE}]+|[${WHITESPACE}]+$`, 'gu');
const HAS_WHITESPACE = new RegExp(`[${WHITESPACE}]`, 'u');

const TEXT_DEFAULT_MAX_LENGTH = 5000;
const EMAIL_MAX_LENGTH = 254;

const isPlainObject = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);

// --- Normalizer ---------------------------------------------------------------------------------

export function trim(value) {
  return value.replace(TRIM, '');
}

export function length(value) {
  return [...value].length;
}

/** Trim strings (also inside arrays) and collapse "not provided" to null. */
export function normalize(value) {
  if (typeof value === 'string') {
    const trimmed = trim(value);
    return trimmed === '' ? null : trimmed;
  }

  if (Array.isArray(value)) {
    return value.length === 0 ? null : value.map((item) => (typeof item === 'string' ? trim(item) : item));
  }

  // WHY: PHP decodes {} and [] to the same empty array, so an empty object must read as "not provided" here too.
  if (isPlainObject(value) && Object.keys(value).length === 0) return null;

  return value === undefined ? null : value;
}

// --- Visibility ---------------------------------------------------------------------------------

function holds(condition, actual) {
  switch (condition.op) {
    case 'eq':
      return actual === condition.value;
    case 'neq':
      return actual !== condition.value;
    case 'in':
      return condition.value.includes(actual);
    default:
      return false;
  }
}

/** @returns {Set<string>} ids of visible fields; hidden values never feed later conditions (I6). */
export function visibleIds(fields, values) {
  const visible = new Set();
  const effective = {};

  for (const field of fields) {
    const condition = field.visible_if;

    if (condition && !holds(condition, effective[condition.field] ?? null)) continue;

    visible.add(field.id);
    effective[field.id] = values[field.id] ?? null;
  }

  return visible;
}

// --- FieldChecks --------------------------------------------------------------------------------

export function isDate(value) {
  const m = typeof value === 'string' && /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!m) return false;

  const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])];
  const date = new Date(Date.UTC(y, mo - 1, d));

  return date.getUTCFullYear() === y && date.getUTCMonth() === mo - 1 && date.getUTCDate() === d;
}

/**
 * Synchronous pattern check: what the conformance suite tests. A matcher may return null to mean "unknown, let the
 * server decide" — render.js does that when its Web Worker times out (JS has no backtrack limit).
 * @returns {boolean|null}
 */
export function matchSync(pattern, value) {
  try {
    return new RegExp(pattern, 'u').test(value);
  } catch {
    return false;
  }
}

function text(rules, value, matcher) {
  if (typeof value !== 'string') return 'type';
  const len = length(value);
  if (len < (rules.min_length ?? 0)) return 'min_length';
  if (len > (rules.max_length ?? TEXT_DEFAULT_MAX_LENGTH)) return 'max_length';
  if (rules.pattern !== undefined && matcher(rules.pattern, value) === false) return 'pattern';
  return null;
}

function email(rules, value) {
  if (typeof value !== 'string') return 'type';
  const len = length(value);
  if (rules.max_length !== undefined && len > rules.max_length) return 'max_length';
  if (len > EMAIL_MAX_LENGTH || value.split('@').length !== 2 || HAS_WHITESPACE.test(value)) return 'email';
  const [local, domain] = value.split('@');
  return local !== '' && domain.includes('.') ? null : 'email';
}

function number(rules, value) {
  if (typeof value !== 'number') return 'type';
  if (rules.min !== undefined && value < rules.min) return 'min';
  if (rules.max !== undefined && value > rules.max) return 'max';
  return rules.integer && !Number.isInteger(value) ? 'integer' : null;
}

function option(options, value) {
  if (typeof value !== 'string') return 'type';
  return options.some((o) => o.value === value) ? null : 'option';
}

function multiselect(options, rules, value) {
  if (!Array.isArray(value)) return 'type';
  if (value.some((item) => typeof item !== 'string')) return 'type';
  const allowed = new Set(options.map((o) => o.value));
  if (value.some((item) => !allowed.has(item)) || new Set(value).size !== value.length) return 'option';
  if (value.length < (rules.min_selected ?? 0)) return 'min_selected';
  return rules.max_selected !== undefined && value.length > rules.max_selected ? 'max_selected' : null;
}

function checkbox(required, value) {
  if (typeof value !== 'boolean') return 'type';
  return required && !value ? 'required' : null;
}

function date(rules, value) {
  if (typeof value !== 'string') return 'type';
  if (!isDate(value)) return 'date';
  if (rules.min !== undefined && value < rules.min) return 'min';
  return rules.max !== undefined && value > rules.max ? 'max' : null;
}

/** @returns {string|null} the first failing code for an already-normalized, provided value */
export function check(field, value, matcher = matchSync) {
  const rules = field.rules ?? {};

  switch (field.type) {
    case 'text':
      return text(rules, value, matcher);
    case 'email':
      return email(rules, value);
    case 'number':
      return number(rules, value);
    case 'select':
    case 'radio':
      return option(field.options, value);
    case 'multiselect':
      return multiselect(field.options, rules, value);
    case 'checkbox':
      return checkbox(field.required ?? false, value);
    case 'date':
      return date(rules, value);
    default:
      return 'type';
  }
}

// --- SubmissionValidator ------------------------------------------------------------------------

/**
 * @param {(pattern: string, value: string) => boolean|null} [matcher] pattern check; defaults to synchronous RegExp
 * @returns {{valid: boolean, errors: Record<string, string[]>, data: Record<string, unknown>}}
 */
export function validate(definition, input, matcher = matchSync) {
  const fields = definition.fields;
  const values = {};

  for (const field of fields) values[field.id] = normalize(input[field.id]);

  const visible = visibleIds(fields, values);
  const errors = {};
  const data = {};

  for (const field of fields) {
    if (!visible.has(field.id)) continue; // I6

    const value = values[field.id];

    if (value === null) {
      if (field.required) errors[field.id] = ['required'];
      continue;
    }

    const code = check(field, value, matcher);
    if (code !== null) errors[field.id] = [code];
    else data[field.id] = value;
  }

  for (const key of Object.keys(input)) {
    if (!(key in values)) errors[key] = ['unknown_field']; // I7
  }

  const valid = Object.keys(errors).length === 0;

  return { valid, errors, data: valid ? data : {} };
}
