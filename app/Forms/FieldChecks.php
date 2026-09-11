<?php

namespace App\Forms;

final class FieldChecks
{
    public const TEXT_DEFAULT_MAX_LENGTH = 5000;

    public const EMAIL_MAX_LENGTH = 254;

    /**
     * @param  mixed  $value  normalized and provided (never null)
     * @return string|null the first failing error code, or null when the value is acceptable
     */
    public static function check(array $field, mixed $value): ?string
    {
        $rules = $field['rules'] ?? [];

        return match ($field['type']) {
            'text' => self::text($rules, $value),
            'email' => self::email($rules, $value),
            'number' => self::number($rules, $value),
            'select', 'radio' => self::option($field['options'], $value),
            'multiselect' => self::multiselect($field['options'], $rules, $value),
            'checkbox' => self::checkbox($field['required'] ?? false, $value),
            'date' => self::date($rules, $value),
        };
    }

    public static function isDate(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function text(array $rules, mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'type';
        }

        $length = Normalizer::length($value);

        if ($length < ($rules['min_length'] ?? 0)) {
            return 'min_length';
        }

        if ($length > ($rules['max_length'] ?? self::TEXT_DEFAULT_MAX_LENGTH)) {
            return 'max_length';
        }

        if (isset($rules['pattern']) && ! SafePattern::matches($rules['pattern'], $value)) {
            return 'pattern';
        }

        return null;
    }

    private static function email(array $rules, mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'type';
        }

        $length = Normalizer::length($value);

        if (isset($rules['max_length']) && $length > $rules['max_length']) {
            return 'max_length';
        }

        if ($length > self::EMAIL_MAX_LENGTH || substr_count($value, '@') !== 1 || Normalizer::containsWhitespace($value)) {
            return 'email';
        }

        [$local, $domain] = explode('@', $value);

        return $local !== '' && str_contains($domain, '.') ? null : 'email';
    }

    private static function number(array $rules, mixed $value): ?string
    {
        if (! is_int($value) && ! is_float($value)) {
            return 'type';
        }

        if (isset($rules['min']) && $value < $rules['min']) {
            return 'min';
        }

        if (isset($rules['max']) && $value > $rules['max']) {
            return 'max';
        }

        return ($rules['integer'] ?? false) && ! is_int($value) ? 'integer' : null;
    }

    private static function option(array $options, mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'type';
        }

        return in_array($value, array_column($options, 'value'), true) ? null : 'option';
    }

    private static function multiselect(array $options, array $rules, mixed $value): ?string
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return 'type';
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                return 'type';
            }
        }

        $allowed = array_column($options, 'value');

        if (array_diff($value, $allowed) !== [] || count(array_unique($value)) !== count($value)) {
            return 'option';
        }

        if (count($value) < ($rules['min_selected'] ?? 0)) {
            return 'min_selected';
        }

        return count($value) > ($rules['max_selected'] ?? PHP_INT_MAX) ? 'max_selected' : null;
    }

    private static function checkbox(bool $required, mixed $value): ?string
    {
        if (! is_bool($value)) {
            return 'type';
        }

        return $required && ! $value ? 'required' : null;
    }

    private static function date(array $rules, mixed $value): ?string
    {
        if (! is_string($value)) {
            return 'type';
        }

        if (! self::isDate($value)) {
            return 'date';
        }

        if (isset($rules['min']) && $value < $rules['min']) {
            return 'min';
        }

        return isset($rules['max']) && $value > $rules['max'] ? 'max' : null;
    }
}
