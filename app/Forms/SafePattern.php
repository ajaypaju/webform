<?php

namespace App\Forms;

final class SafePattern
{
    public const MAX_PATTERN_BYTES = 500;

    public const MAX_SUBJECT_CHARS = 5000;

    // WHY: a control character can't appear in an accepted pattern (compiles() rejects it), so wrapping never
    // needs escaping and never changes the pattern's meaning — unlike "/" which customers legitimately use in URLs.
    private const DELIMITER = "\x01";

    // WHY: "Du" — "$" matches only at the very end like JS, and both engines see the same code points.
    private const MODIFIERS = 'Du';

    private const BACKTRACK_LIMIT = 100_000;

    private const RECURSION_LIMIT = 10_000;

    public static function compiles(string $pattern): bool
    {
        if (strlen($pattern) > self::MAX_PATTERN_BYTES || str_contains($pattern, self::DELIMITER)) {
            return false;
        }

        return self::run($pattern, '') !== false;
    }

    // I8
    public static function matches(string $pattern, string $subject): bool
    {
        if (strlen($pattern) > self::MAX_PATTERN_BYTES || mb_strlen($subject) > self::MAX_SUBJECT_CHARS) {
            return false;
        }

        $result = self::run($pattern, $subject);

        if ($result === false) {
            error_log(sprintf('SafePattern: preg_match failed (%s) for pattern %s', preg_last_error_msg(), json_encode($pattern)));
        }

        return $result === 1;
    }

    /**
     * preg_match with tight limits and every failure reported as false, never as a warning or exception.
     */
    private static function run(string $pattern, string $subject): int|false
    {
        $previous = [
            'pcre.backtrack_limit' => ini_set('pcre.backtrack_limit', (string) self::BACKTRACK_LIMIT),
            'pcre.recursion_limit' => ini_set('pcre.recursion_limit', (string) self::RECURSION_LIMIT),
        ];

        set_error_handler(static fn (): bool => true);

        try {
            return preg_match(self::DELIMITER.$pattern.self::DELIMITER.self::MODIFIERS, $subject);
        } finally {
            restore_error_handler();

            foreach ($previous as $option => $value) {
                if ($value !== false) {
                    ini_set($option, $value);
                }
            }
        }
    }
}
