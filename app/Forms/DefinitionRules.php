<?php

namespace App\Forms;

final class DefinitionRules
{
    public const TYPES = ['text', 'email', 'number', 'select', 'multiselect', 'radio', 'checkbox', 'date'];

    private const WITH_OPTIONS = ['select', 'multiselect', 'radio'];

    private const RULES = [
        'text' => ['min_length', 'max_length', 'pattern'],
        'email' => ['max_length'],
        'number' => ['min', 'max', 'integer'],
        'select' => [],
        'multiselect' => ['min_selected', 'max_selected'],
        'radio' => [],
        'checkbox' => [],
        'date' => ['min', 'max'],
    ];

    private const RANGES = [['min', 'max'], ['min_length', 'max_length'], ['min_selected', 'max_selected']];

    private const OPS = ['eq', 'neq', 'in'];

    // WHY: \p{..} limited to short General_Category names: scripts need "Script=" in JS, and PCRE2 10.44 does not
    // compile the long names (\p{Letter}) at all.
    private const GENERAL_CATEGORIES = ['L', 'Lu', 'Ll', 'Lt', 'Lm', 'Lo', 'M', 'N', 'Nd', 'Nl', 'No', 'P', 'S', 'Z'];

    /**
     * Validate a form definition at save time. Semantics: conformance/README.md.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string> error codes, one per violation in field order; empty when valid
     */
    public function validate(array $definition): array
    {
        $fields = $definition['fields'] ?? null;

        if (! is_array($fields) || ! array_is_list($fields)) {
            return ['fields'];
        }

        $ids = array_map(fn ($field) => is_array($field) ? ($field['id'] ?? null) : null, $fields);
        $errors = [];
        $earlier = [];

        foreach ($fields as $index => $field) {
            if (! is_array($field)) {
                $errors[] = 'field_id';

                continue;
            }

            $id = $ids[$index];

            // I5
            if (! is_string($id) || preg_match('/^[a-z0-9_]{1,40}$/D', $id) !== 1) {
                $errors[] = 'field_id';
            } elseif (isset($earlier[$id])) {
                $errors[] = 'duplicate_id';
            }

            $type = $field['type'] ?? null;

            if (! in_array($type, self::TYPES, true)) {
                $errors[] = 'field_type';
            } else {
                array_push($errors, ...self::options($type, $field), ...self::rules($type, $field['rules'] ?? []));
            }

            array_push($errors, ...self::visibleIf($field['visible_if'] ?? null, $ids, $earlier));

            if (is_string($id)) {
                $earlier[$id] = true;
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private static function options(string $type, array $field): array
    {
        if (! in_array($type, self::WITH_OPTIONS, true)) {
            return array_key_exists('options', $field) ? ['options_forbidden'] : [];
        }

        $options = $field['options'] ?? null;

        if (! is_array($options) || $options === [] || ! array_is_list($options)) {
            return ['options_missing'];
        }

        $values = [];

        foreach ($options as $option) {
            $value = is_array($option) ? ($option['value'] ?? null) : null;

            if (! is_string($value)) {
                return ['option_value'];
            }

            $values[] = $value;
        }

        return count(array_unique($values)) === count($values) ? [] : ['duplicate_option'];
    }

    /** @return list<string> */
    private static function rules(string $type, mixed $rules): array
    {
        if (! is_array($rules)) {
            return ['rule_value'];
        }

        $errors = [];

        foreach ($rules as $name => $value) {
            if (! in_array($name, self::RULES[$type], true)) {
                $errors[] = 'rule_forbidden';

                continue;
            }

            $error = match ($name) {
                'min_length', 'max_length', 'min_selected', 'max_selected' => is_int($value) && $value >= 0 ? null : 'rule_value',
                'min', 'max' => ($type === 'date' ? FieldChecks::isDate($value) : is_int($value) || is_float($value)) ? null : 'rule_value',
                'integer' => is_bool($value) ? null : 'rule_value',
                'pattern' => self::pattern($value),
            };

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        foreach (self::RANGES as [$low, $high]) {
            if (isset($rules[$low], $rules[$high]) && $rules[$low] > $rules[$high]) {
                $errors[] = 'min_max';
            }
        }

        return $errors;
    }

    /**
     * @param  list<mixed>  $ids  every field id in the definition
     * @param  array<string, true>  $earlier  ids of the fields before this one
     * @return list<string>
     */
    private static function visibleIf(mixed $condition, array $ids, array $earlier): array
    {
        if ($condition === null) {
            return [];
        }

        $reference = is_array($condition) ? ($condition['field'] ?? null) : null;

        if (! is_string($reference) || ! in_array($reference, $ids, true)) {
            return ['visible_if_field'];
        }

        // I6: conditions only look backwards, so evaluation order is the definition order and cycles can't exist.
        if (! isset($earlier[$reference])) {
            return ['visible_if_order'];
        }

        $op = $condition['op'] ?? null;

        if (! in_array($op, self::OPS, true)) {
            return ['visible_if_op'];
        }

        $value = $condition['value'] ?? null;

        return $op === 'in' && ! (is_array($value) && array_is_list($value)) ? ['visible_if_value'] : [];
    }

    private static function pattern(mixed $pattern): ?string
    {
        if (! is_string($pattern)) {
            return 'pattern_invalid';
        }

        // I8
        if (! self::isPortable($pattern)) {
            return 'pattern_unsafe';
        }

        return SafePattern::compiles($pattern) ? null : 'pattern_invalid';
    }

    /**
     * Only the regex subset that PCRE and JS (compiled with the u flag) read identically, with nothing that can
     * backtrack catastrophically on purpose: no backreferences, lookaround, atomic/possessive constructs, inline
     * flags, PCRE-only escapes, POSIX classes, octal, script properties, or PCRE's lenient lone "{ } ]".
     * Groups may be "(...)", "(?:...)" or "(?<name>...)". An unterminated class is left to the compile check.
     */
    private static function isPortable(string $pattern): bool
    {
        $length = strlen($pattern);
        $inClass = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            $next = $pattern[$i + 1] ?? '';

            if ($char === '\\') {
                $consumed = self::portableEscapeLength(substr($pattern, $i + 1, 24), $inClass);

                if ($consumed === 0) {
                    return false;
                }

                $i += $consumed;

                continue;
            }

            if ($inClass) {
                if ($char === ']') {
                    $inClass = false;
                } elseif ($char === '[' && $next === ':') {
                    return false;
                }

                continue;
            }

            switch ($char) {
                case '[':
                    // WHY: "[]" and "[^]" are an empty / any-character class in JS but an unterminated class in PCRE.
                    if ($next === ']' || ($next === '^' && ($pattern[$i + 2] ?? '') === ']')) {
                        return false;
                    }

                    $inClass = true;
                    break;
                case '(':
                    if (($next === '?' || $next === '*')
                        && preg_match('/^\(\?(?::|<[A-Za-z_][A-Za-z0-9_]*>)/', substr($pattern, $i, 44)) !== 1) {
                        return false;
                    }
                    break;
                case '{':
                    if (preg_match('/^\{\d+(?:,\d*)?\}/', substr($pattern, $i, 24), $m) !== 1) {
                        return false;
                    }

                    $i += strlen($m[0]) - 1;

                    if (($pattern[$i + 1] ?? '') === '+') {
                        return false;
                    }
                    break;
                case '}':
                case ']':
                    return false;
                case '+':
                case '*':
                case '?':
                    if ($next === '+') {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }

    /** @return int bytes of the escape after the backslash, 0 when it is not portable */
    private static function portableEscapeLength(string $tail, bool $inClass): int
    {
        // Identity escapes: only regex syntax characters (JS u-mode rejects the rest), "-" only inside a class.
        $punctuation = '[\^$\\\\.*+?()\[\]{}|\/'.($inClass ? '\-' : '').']';
        $categories = implode('|', self::GENERAL_CATEGORIES);

        $portable = preg_match(
            '/^(?:'.$punctuation.'|[dDwWsSbBnrtf]|0(?!\d)|c[A-Za-z]|x[0-9A-Fa-f]{2}|[pP]\{(?:'.$categories.')\})/',
            $tail,
            $m,
        ) === 1;

        return $portable ? strlen($m[0]) : 0;
    }
}
