<?php

namespace App\Forms;

final class Visibility
{
    /**
     * @param  list<array<string, mixed>>  $fields  definition fields, in order
     * @param  array<string, mixed>  $values  normalized input by field id (null = not provided)
     * @return array<string, true> ids of the visible fields
     */
    public static function evaluate(array $fields, array $values): array
    {
        $visible = [];
        $effective = [];

        foreach ($fields as $field) {
            $id = $field['id'];
            $condition = $field['visible_if'] ?? null;

            if ($condition !== null && ! self::holds($condition, $effective[$condition['field']] ?? null)) {
                continue;
            }

            $visible[$id] = true;
            // I6: only visible values feed later conditions; a hidden field reads as not provided downstream.
            $effective[$id] = $values[$id] ?? null;
        }

        return $visible;
    }

    private static function holds(array $condition, mixed $actual): bool
    {
        return match ($condition['op']) {
            'eq' => $actual === $condition['value'],
            'neq' => $actual !== $condition['value'],
            'in' => in_array($actual, $condition['value'], true),
        };
    }
}
