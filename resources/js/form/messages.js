// One place for code -> text, used by the public page (submission codes) and the dashboard (definition and publish
// codes), so the two surfaces can't drift. Codes are the contract (conformance/README.md); these are just words.

export const SUBMISSION_MESSAGES = {
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

export const DEFINITION_MESSAGES = {
  fields: 'The definition must have a list of fields.',
  field_id: 'Field id must be 1–40 characters of a-z, 0-9 or _.',
  duplicate_id: 'Another field already uses this id.',
  field_type: 'Unknown field type.',
  visible_if_field: 'The visibility condition refers to a field that does not exist.',
  visible_if_order: 'A visibility condition may only refer to a field above this one.',
  visible_if_op: 'Unknown visibility operator (use eq, neq or in).',
  visible_if_value: 'The "in" operator needs a list of values.',
  options_missing: 'This field type needs at least one option.',
  options_forbidden: 'This field type does not take options.',
  option_value: 'Every option needs a text value.',
  duplicate_option: 'Two options share the same value.',
  pattern_unsafe: 'This pattern uses a construct that is not portable or safe (backreferences, lookaround, possessive quantifiers, inline flags, POSIX classes, \\A \\Z \\z \\Q \\E).',
  pattern_invalid: 'This pattern does not compile.',
  rule_forbidden: 'This rule does not apply to this field type.',
  rule_value: 'This rule has an invalid value.',
  min_max: 'The minimum is greater than the maximum.',
};

/** Publish-time refusals carry a field; the prior versions explain why. */
export function publishMessage(error, versions) {
  if (error.code !== 'type_changed') return DEFINITION_MESSAGES[error.code] ?? error.code;

  const first = versions.find((v) => v.definition.fields.some((f) => f.id === error.field));
  const type = first?.definition.fields.find((f) => f.id === error.field)?.type;

  return first
    ? `${error.field} was published as type ${type} in v${first.version_no}; type cannot change.`
    : `${error.field} was published with a different type before; type cannot change.`;
}
