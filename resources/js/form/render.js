// Enhances the server-rendered form: live visibility (I6), inline errors, typed value collection, submit with
// retry and a persisted pending submission (I2). DOM writes go through textContent / setAttribute / hidden only —
// never markup from strings (I9).
import { validate, visibleIds, normalize } from './validate.js';
import { patternMatcher } from './patterns.js';
import { uuidv7, submitWithRetry } from './submit.js';
import { SUBMISSION_MESSAGES as MESSAGES } from './messages.js';

// WHY: a resumed submission carries a fresh render token; sending it before the minimum fill time would be
// treated as a bot.
const RESUME_DELAY_MS = 2500;

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

function write(form, field, value) {
  const el = form.elements.namedItem(field.id);
  if (!el || value === undefined) return;

  switch (field.type) {
    case 'checkbox':
      el.checked = value === true;
      break;
    case 'radio':
      for (const radio of form.querySelectorAll(`input[name="${CSS.escape(field.id)}"]`)) radio.checked = radio.value === value;
      break;
    case 'multiselect':
      for (const option of el.options) option.selected = Array.isArray(value) && value.includes(option.value);
      break;
    default:
      el.value = String(value);
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

const storageKey = (formId) => `webform:pending:${formId}`;

function loadPending(formId) {
  try {
    return JSON.parse(localStorage.getItem(storageKey(formId))) ?? null;
  } catch {
    return null;
  }
}

function savePending(formId, pending) {
  try {
    localStorage.setItem(storageKey(formId), JSON.stringify(pending));
  } catch {
    // Private mode or full storage: the retry loop still runs for this page load.
  }
}

function clearPending(formId) {
  try {
    localStorage.removeItem(storageKey(formId));
  } catch {
    // ignore
  }
}

/**
 * @param {{definition?: object, preview?: boolean}} [options] preview: validate and show errors only — no network,
 *   no localStorage — used by the dashboard's live preview against a local definition.
 */
export function enhance(form, { definition = null, preview = false } = {}) {
  const { fields } = definition ?? readDefinition(document);
  const formId = form.dataset.formId;
  const status = form.querySelector('[data-status]') ?? document.querySelector('[data-status]');
  const say = (text) => { if (status) status.textContent = text; };

  const refresh = () => applyVisibility(form, fields, collect(form, fields));
  form.addEventListener('input', refresh);
  form.addEventListener('change', refresh);
  refresh();

  // WHY: one id per submission attempt series, reused on every retry, so the server can collapse duplicates (I2).
  let pending = preview ? null : loadPending(formId);

  async function send(input) {
    const body = {
      submission_id: pending.submission_id,
      form_version_id: form.dataset.versionId,
      render_token: form.dataset.renderToken,
      data: input,
      honeypot: form.elements.namedItem('honeypot')?.value ?? '',
    };
    savePending(formId, { submission_id: pending.submission_id, data: input });
    form.querySelector('button[type="submit"]').disabled = true;

    const result = await submitWithRetry(form.action, body, {
      onRetry: ({ attempt, delay }) => say(`Could not reach the server (attempt ${attempt}); retrying in ${Math.round(delay / 1000)}s. Your answers are kept on this device.`),
    });

    form.querySelector('button[type="submit"]').disabled = false;

    switch (result.status) {
      case 202:
        clearPending(formId);
        pending = null;
        form.hidden = true;
        document.querySelector('[data-thanks]').hidden = false;
        say('');
        return;
      case 422:
        showErrors(form, Array.isArray(result.body?.errors) ? {} : result.body?.errors ?? {});
        say(Array.isArray(result.body?.errors) ? 'The submission could not be read. Please reload the page and try again.' : 'Please correct the highlighted fields.');
        return;
      case 409:
        say('This form has been updated since the page was opened. Reload to get the latest version; your answers are kept on this device.');
        return;
      default:
        say('The server is unavailable right now. Your answers are kept on this device; reload this page later to send them.');
    }
  }

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const input = collect(form, fields);
    const values = Object.fromEntries(fields.map((field) => [field.id, normalize(input[field.id])]));

    // WHY: regexes run in a worker with a time budget; the main thread never blocks on a customer's pattern.
    const result = validate({ fields }, input, await patternMatcher(fields, values));
    showErrors(form, result.errors);
    if (!result.valid) return;

    if (preview) {
      say('Valid. In the live form this would now be submitted.');
      return;
    }

    pending ??= { submission_id: uuidv7() };
    await send(input);
  });

  if (pending) {
    for (const field of fields) write(form, field, pending.data?.[field.id]);
    refresh();
    say('Sending your earlier answers…');
    setTimeout(() => form.requestSubmit(), RESUME_DELAY_MS);
  }
}
