// The form builder. State is the draft definition; the editor and the preview are rebuilt from it after every
// change. All DOM is built with createElement / textContent / setAttribute — tenant content is hostile here too (I9).
import { enhance } from '../form/render.js';
import { DEFINITION_MESSAGES, publishMessage } from '../form/messages.js';

const TYPES = ['text', 'email', 'number', 'select', 'multiselect', 'radio', 'checkbox', 'date'];
const WITH_OPTIONS = new Set(['select', 'multiselect', 'radio']);
const RULES = {
  text: [['min_length', 'number'], ['max_length', 'number'], ['pattern', 'text']],
  email: [['max_length', 'number']],
  number: [['min', 'number'], ['max', 'number'], ['integer', 'checkbox']],
  multiselect: [['min_selected', 'number'], ['max_selected', 'number']],
  date: [['min', 'date'], ['max', 'date']],
};

// --- tiny DOM helpers ---------------------------------------------------------------------------

function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (v === false || v === null || v === undefined) continue;
    if (k === 'text') node.textContent = v;
    else if (k === 'checked' || k === 'disabled' || k === 'hidden' || k === 'selected') node[k] = v;
    else node.setAttribute(k, v === true ? '' : String(v));
  }
  for (const child of children) node.append(child);
  return node;
}

function labelled(text, control) {
  return el('label', { class: 'field' }, [el('span', { text }), control]);
}

// --- editor -------------------------------------------------------------------------------------

export function createBuilder(root, data) {
  let definition = structuredClone(data.draft);
  let fieldErrors = data.field_errors ?? {};
  let globalErrors = data.definition_errors ?? [];
  let publishErrors = [];
  const versions = data.versions ?? [];
  const csrf = data.csrf;
  const fieldsRoot = root.querySelector('[data-fields]');
  let previewHost = root.querySelector('[data-preview]');
  const message = root.querySelector('[data-message]');
  const globalList = root.querySelector('[data-global-errors]');
  const say = (text) => { message.textContent = text; };

  function fields() { return definition.fields; }

  // Structural edits rebuild the editor; keystrokes only refresh the preview, so inputs keep focus.
  function update(mutate) {
    mutate(definition);
    render();
  }

  function live(mutate) {
    mutate(definition);
    renderPreview();
  }

  function fieldEditor(field, index) {
    const errors = [...(fieldErrors[field.id] ?? []).map((c) => DEFINITION_MESSAGES[c] ?? c), ...publishErrors.filter((e) => e.field === field.id).map((e) => publishMessage(e, versions))];
    const box = el('fieldset', { class: 'field-box' + (errors.length ? ' has-errors' : ''), 'data-field-editor': field.id ?? '' });

    box.append(el('legend', { text: `${index + 1}. ${field.label || '(untitled)'} — ${field.type}` }));
    box.append(el('p', { class: 'muted', text: `id: ${field.id ?? '(assigned when saved)'}` }));

    const label = el('input', { type: 'text', value: field.label ?? '' });
    label.addEventListener('input', () => live((d) => { d.fields[index].label = label.value; }));
    label.addEventListener('change', render);
    box.append(labelled('Label', label));

    const help = el('input', { type: 'text', value: field.help_text ?? '' });
    help.addEventListener('input', () => live((d) => { help.value === '' ? delete d.fields[index].help_text : (d.fields[index].help_text = help.value); }));
    box.append(labelled('Help text', help));

    const required = el('input', { type: 'checkbox', checked: !!field.required });
    required.addEventListener('change', () => update((d) => { d.fields[index].required = required.checked; }));
    box.append(labelled('Required', required));

    const type = el('select', {}, TYPES.map((t) => el('option', { value: t, text: t, selected: t === field.type })));
    type.addEventListener('change', () => update((d) => {
      d.fields[index].type = type.value;
      if (WITH_OPTIONS.has(type.value)) d.fields[index].options ??= [{ value: 'a', label: 'A' }];
      else delete d.fields[index].options;
      delete d.fields[index].rules;
    }));
    box.append(labelled('Type', type));

    if (WITH_OPTIONS.has(field.type)) box.append(optionsEditor(field, index));
    if (RULES[field.type]) box.append(rulesEditor(field, index));
    box.append(visibilityEditor(field, index));

    const controls = el('div', { class: 'controls' });
    const up = el('button', { type: 'button', text: '↑ Move up', disabled: index === 0 });
    up.addEventListener('click', () => update((d) => { [d.fields[index - 1], d.fields[index]] = [d.fields[index], d.fields[index - 1]]; }));
    const down = el('button', { type: 'button', text: '↓ Move down', disabled: index === fields().length - 1 });
    down.addEventListener('click', () => update((d) => { [d.fields[index + 1], d.fields[index]] = [d.fields[index], d.fields[index + 1]]; }));
    const remove = el('button', { type: 'button', text: 'Remove', class: 'danger' });
    remove.addEventListener('click', () => update((d) => { d.fields.splice(index, 1); }));
    controls.append(up, down, remove);
    box.append(controls);

    if (errors.length) box.append(el('ul', { class: 'errors' }, errors.map((e) => el('li', { text: e }))));

    return box;
  }

  function optionsEditor(field, index) {
    const wrap = el('div', { class: 'options' }, [el('span', { text: 'Options (value → label)' })]);
    (field.options ?? []).forEach((option, i) => {
      const value = el('input', { type: 'text', value: option.value, 'aria-label': 'option value' });
      const label = el('input', { type: 'text', value: option.label, 'aria-label': 'option label' });
      value.addEventListener('input', () => live((d) => { d.fields[index].options[i].value = value.value; }));
      label.addEventListener('input', () => live((d) => { d.fields[index].options[i].label = label.value; }));
      const del = el('button', { type: 'button', text: '×', 'aria-label': 'remove option' });
      del.addEventListener('click', () => update((d) => { d.fields[index].options.splice(i, 1); }));
      wrap.append(el('div', { class: 'row' }, [value, label, del]));
    });
    const add = el('button', { type: 'button', text: 'Add option' });
    add.addEventListener('click', () => update((d) => { (d.fields[index].options ??= []).push({ value: '', label: '' }); }));
    wrap.append(add);
    return wrap;
  }

  function rulesEditor(field, index) {
    const wrap = el('div', { class: 'rules' }, [el('span', { text: 'Rules' })]);
    for (const [name, kind] of RULES[field.type]) {
      const current = field.rules?.[name];
      const input = kind === 'checkbox'
        ? el('input', { type: 'checkbox', checked: current === true })
        : el('input', { type: kind === 'number' ? 'number' : kind === 'date' ? 'date' : 'text', value: current ?? '' });
      input.addEventListener(kind === 'checkbox' ? 'change' : 'input', () => (kind === 'checkbox' ? update : live)((d) => {
        const rules = (d.fields[index].rules ??= {});
        if (kind === 'checkbox') input.checked ? (rules[name] = true) : delete rules[name];
        else if (input.value === '') delete rules[name];
        else rules[name] = kind === 'number' ? Number(input.value) : input.value;
        if (Object.keys(rules).length === 0) delete d.fields[index].rules;
      }));
      wrap.append(labelled(name, input));
    }
    return wrap;
  }

  // I6: only fields above this one can be referenced; the value input follows the referenced field's type.
  function visibilityEditor(field, index) {
    const above = fields().slice(0, index).filter((f) => f.id);
    const wrap = el('div', { class: 'visibility' });
    const enabled = el('input', { type: 'checkbox', checked: !!field.visible_if, disabled: above.length === 0 });
    enabled.addEventListener('change', () => update((d) => {
      if (enabled.checked) d.fields[index].visible_if = { field: above[0].id, op: 'eq', value: defaultValue(above[0]) };
      else delete d.fields[index].visible_if;
    }));
    wrap.append(labelled(above.length ? 'Show only when…' : 'Show only when… (add a field above first)', enabled));
    if (!field.visible_if) return wrap;

    const ref = above.find((f) => f.id === field.visible_if.field) ?? above[0];
    const refSelect = el('select', {}, above.map((f) => el('option', { value: f.id, text: `${f.label || f.id} (${f.type})`, selected: f.id === ref.id })));
    refSelect.addEventListener('change', () => update((d) => {
      const target = above.find((f) => f.id === refSelect.value);
      d.fields[index].visible_if = { field: target.id, op: 'eq', value: defaultValue(target) };
    }));
    const op = el('select', {}, ['eq', 'neq', 'in'].map((o) => el('option', { value: o, text: o, selected: o === field.visible_if.op })));
    op.addEventListener('change', () => update((d) => {
      d.fields[index].visible_if.op = op.value;
      d.fields[index].visible_if.value = op.value === 'in' ? [defaultValue(ref)] : defaultValue(ref);
    }));
    wrap.append(labelled('Field', refSelect), labelled('Operator', op), labelled('Value', valueInput(ref, field.visible_if, index)));
    return wrap;
  }

  function defaultValue(ref) {
    if (WITH_OPTIONS.has(ref.type)) return ref.options?.[0]?.value ?? '';
    if (ref.type === 'checkbox') return true;
    if (ref.type === 'number') return 0;
    return '';
  }

  function valueInput(ref, condition, index) {
    const multiple = condition.op === 'in';
    const set = (v) => live((d) => { d.fields[index].visible_if.value = v; });

    if (WITH_OPTIONS.has(ref.type)) {
      const select = el('select', { multiple }, (ref.options ?? []).map((o) => el('option', {
        value: o.value, text: o.label || o.value,
        selected: multiple ? (Array.isArray(condition.value) && condition.value.includes(o.value)) : condition.value === o.value,
      })));
      select.addEventListener('change', () => set(multiple ? [...select.selectedOptions].map((o) => o.value) : select.value));
      return select;
    }
    if (ref.type === 'checkbox') {
      const select = el('select', {}, [el('option', { value: 'true', text: 'checked', selected: condition.value === true }), el('option', { value: 'false', text: 'not checked', selected: condition.value === false })]);
      select.addEventListener('change', () => set(select.value === 'true'));
      return select;
    }
    const input = el('input', { type: ref.type === 'number' && !multiple ? 'number' : 'text', value: Array.isArray(condition.value) ? condition.value.join(', ') : String(condition.value ?? '') });
    input.addEventListener('input', () => {
      const parse = (s) => (ref.type === 'number' ? Number(s) : s);
      set(multiple ? input.value.split(',').map((s) => parse(s.trim())).filter((s) => s !== '') : parse(input.value));
    });
    return input;
  }

  // --- preview ----------------------------------------------------------------------------------

  function previewField(field) {
    const id = field.id ?? `new_${Math.random().toString(36).slice(2, 8)}`;
    const box = el('fieldset', { 'data-field': id });
    const required = !!field.required;
    const labelText = field.label ?? '';

    switch (field.type) {
      case 'text': case 'email': case 'number': case 'date': {
        box.append(el('label', { for: `p-${id}`, class: required ? 'required' : null, text: labelText }));
        box.append(el('input', { id: `p-${id}`, name: id, type: field.type, step: field.type === 'number' ? 'any' : null, required }));
        break;
      }
      case 'select': case 'multiselect': {
        box.append(el('label', { for: `p-${id}`, class: required ? 'required' : null, text: labelText }));
        const select = el('select', { id: `p-${id}`, name: id, multiple: field.type === 'multiselect', required });
        if (field.type === 'select') select.append(el('option', { value: '', text: 'Choose…' }));
        for (const o of field.options ?? []) select.append(el('option', { value: o.value, text: o.label }));
        box.append(select);
        break;
      }
      case 'radio': {
        box.append(el('legend', { class: required ? 'required' : null, text: labelText }));
        for (const o of field.options ?? []) box.append(el('label', {}, [el('input', { type: 'radio', name: id, value: o.value }), document.createTextNode(` ${o.label}`)]));
        break;
      }
      case 'checkbox':
        box.append(el('label', { class: required ? 'required' : null }, [el('input', { type: 'checkbox', name: id, value: '1' }), document.createTextNode(` ${labelText}`)]));
        break;
    }
    if (field.help_text) box.append(el('p', { class: 'help', text: field.help_text }));
    box.append(el('p', { class: 'error', 'data-error-for': id, 'aria-live': 'polite' }));
    return box;
  }

  function renderPreview() {
    const form = el('form', { 'data-preview': '', 'data-form-id': previewHost.dataset.formId, novalidate: '' });
    const withIds = { fields: fields().map((f) => (f.id ? f : { ...f, id: `new_${fields().indexOf(f)}` })) };
    for (const field of withIds.fields) form.append(previewField(field));
    form.append(el('button', { type: 'submit', text: 'Submit (preview)' }), el('p', { class: 'status', 'data-status': '', 'aria-live': 'polite' }));
    previewHost.replaceWith(form);
    previewHost = form;
    enhance(form, { definition: withIds, preview: true });
  }

  // --- render / save / publish ------------------------------------------------------------------

  function render() {
    fieldsRoot.replaceChildren(...fields().map(fieldEditor));
    globalList.replaceChildren(...[...globalErrors.map((c) => DEFINITION_MESSAGES[c] ?? c), ...publishErrors.filter((e) => !e.field).map((e) => publishMessage(e, versions))].map((t) => el('li', { text: t })));
    renderPreview();
  }

  async function call(method, path, body) {
    const res = await fetch(path, { method, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(body ?? {}) });
    return { status: res.status, body: await res.json().catch(() => null) };
  }

  async function save() {
    say('Saving…');
    const { status, body } = await call('PUT', `/dashboard/forms/${data.form.id}/draft`, { definition });
    if (status !== 200) { say(body?.errors ? 'Not saved: ' + body.errors.map((e) => DEFINITION_MESSAGES[e.code] ?? e.code).join(' ') : `Not saved (${status}).`); return false; }
    definition = body.definition;
    fieldErrors = body.field_errors;
    globalErrors = body.definition_errors;
    publishErrors = [];
    render();
    say(globalErrors.length ? `Saved with ${globalErrors.length} issue(s) to fix before publishing.` : 'Saved.');
    return true;
  }

  async function publish() {
    if (!(await save())) return;
    say('Publishing…');
    const { status, body } = await call('POST', `/dashboard/forms/${data.form.id}/publish`);
    if (status === 201) {
      root.querySelector('[data-status]').textContent = 'published';
      root.querySelector('[data-version-no]').textContent = String(body.version_no);
      const link = root.querySelector('[data-page-link]');
      link.hidden = false;
      link.setAttribute('href', body.page_url);
      versions.push({ version_no: body.version_no, definition: structuredClone(definition) });
      say(`Published v${body.version_no}.`);
      return;
    }
    publishErrors = body?.errors ?? [{ code: `http_${status}` }];
    render();
    say('Not published: fix the highlighted fields.');
  }

  root.querySelector('[data-action="save"]').addEventListener('click', save);
  root.querySelector('[data-action="publish"]').addEventListener('click', publish);
  root.querySelector('[data-action="add"]').addEventListener('click', () => update((d) => {
    const type = root.querySelector('[data-add-type]').value;
    d.fields.push({ id: null, type, label: '', required: false, ...(WITH_OPTIONS.has(type) ? { options: [{ value: 'a', label: 'A' }] } : {}) });
  }));

  render();
}
