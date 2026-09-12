// Enhances the server-rendered form: live visibility (I6), inline error display, typed value collection.
// DOM writes go through textContent / setAttribute / hidden only — never markup from strings (I9).
import { validate, visibleIds, normalize } from './validate.js';
import { patternMatcher } from './patterns.js';

const MESSAGES = {
  required: 'This field is required.',
  type: 'This value has the wrong type.',
  min_length: 'Too short.',
  max_length: 'Too long.',
  pattern: 'This value does not match the expected format.',
  email: 'Enter a valid email address.',
  min: 'Too small or too early.',
  max: 'Too large or too late.',
  integer: 'Enter a whole number.',
  option: 'Choose one of the listed options.',
  min_selected: 'Select more options.',
  max_selected: 'Select fewer options.',
  date: 'Enter a valid date (YYYY-MM-DD).',
  unknown_field: 'Unexpected field.',
};

export function readDefinition(root) {
  return JSON.parse(root.querySelector('#form-definition').textContent);
}

/** Current input as the typed JSON the server expects. Absent/blank inputs are simply not sent. */
export function collect(form, fields) {
  const input = {};

  for (const field of fields) {
    const value = read(form, field);
    if (value !== null) input[field.id] = value;
  }

  return input;
}

function read(form, field) {
  const el = form.elements.namedItem(field.id);
  if (!el) return null;

  switch (field.type) {
    case 'checkbox':
      return el.checked;
    case 'radio': {
      const checked = form.querySelector(`input[name="${CSS.escape(field.id)}"]:checked`);
      return checked ? checked.value : null;
    }
    case 'multiselect':
      return [...el.selectedOptions].map((o) => o.value);
    case 'number': {
      const raw = el.value.trim();
      return raw === '' ? null : Number.isNaN(Number(raw)) ? raw : Number(raw);
    }
    default:
      return el.value === '' ? null : el.value;
  }
}

export function applyVisibility(form, fields, input) {
  const values = {};
  for (const field of fields) values[field.id] = normalize(input[field.id]);
  const visible = visibleIds(fields, values);

  for (const field of fields) {
    const wrapper = form.querySelector(`[data-field="${CSS.escape(field.id)}"]`);
    if (wrapper) wrapper.hidden = !visible.has(field.id);
  }
}

export function showErrors(form, errors) {
  for (const slot of form.querySelectorAll('[data-error-for]')) {
    const codes = errors[slot.getAttribute('data-error-for')];
    slot.textContent = codes ? MESSAGES[codes[0]] ?? codes[0] : '';
  }
}

export function enhance(form) {
  const { fields } = readDefinition(document);

  const refresh = () => applyVisibility(form, fields, collect(form, fields));
  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
  refresh();

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const input = collect(form, fields);
    const values = Object.fromEntries(fields.map((field) => [field.id, normalize(input[field.id])]));

    // WHY: regexes run in a worker with a time budget; the main thread never blocks on a customer's pattern.
    const result = validate({ fields }, input, await patternMatcher(fields, values));
    showErrors(form, result.errors);
    form.dispatchEvent(new CustomEvent('form:validated', { detail: result }));
  });
}
