<?php

namespace App\Forms;

final class Normalizer
{
    // WHY: exactly JS String.prototype.trim's set (WhiteSpace + LineTerminator), so the client and server agree
    // on what "empty" means. PHP's trim() and \s cover a different set.
    private const WHITESPACE = '\x{9}-\x{D}\x{20}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /**
     * Trim strings (also inside arrays), collapse "not provided" to null, and fold whole-number floats to int.
     */
    public static function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = self::trim($value);

            return $value === '' ? null : $value;
        }

        if (is_array($value)) {
            return $value === [] ? null : array_map(fn ($item) => is_string($item) ? self::trim($item) : $item, $value);
        }

        // WHY: JSON.stringify(5.0) is "5"; only PHP's decoder can tell them apart, so storing the int keeps parity.
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
            return (int) $value;
        }

        return $value;
    }

    public static function trim(string $value): string
    {
        return preg_replace('/^['.self::WHITESPACE.']+|['.self::WHITESPACE.']+$/u', '', $value) ?? $value;
    }

    public static function containsWhitespace(string $value): bool
    {
        return preg_match('/['.self::WHITESPACE.']/u', $value) === 1;
    }

    public static function length(string $value): int
    {
        return mb_strlen($value);
    }
}
