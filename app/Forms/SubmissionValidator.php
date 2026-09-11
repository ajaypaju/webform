<?php

namespace App\Forms;

final class SubmissionValidator
{
    /**
     * Visibility -> strip hidden -> strict type/rule checks. Pure PHP: Laravel's Validator is loosely typed
     * (numeric strings, strtotime dates, RFC email) and would break parity with the JS validator. Semantics: conformance/README.md.
     *
     * @param  array<string, mixed>  $definition  a published form definition
     * @param  array<string, mixed>  $input  decoded JSON body
     */
    public function validate(array $definition, array $input): ValidationResult
    {
        $fields = $definition['fields'];
        $values = [];

        foreach ($fields as $field) {
            $values[$field['id']] = Normalizer::normalize($input[$field['id']] ?? null);
        }

        $visible = Visibility::evaluate($fields, $values);
        $errors = [];
        $data = [];

        foreach ($fields as $field) {
            $id = $field['id'];

            // I6: hidden fields are never required, get no rules, and are never stored.
            if (! isset($visible[$id])) {
                continue;
            }

            if ($values[$id] === null) {
                if ($field['required'] ?? false) {
                    $errors[$id] = ['required'];
                }

                continue;
            }

            $code = FieldChecks::check($field, $values[$id]);

            if ($code !== null) {
                $errors[$id] = [$code];
            } else {
                $data[$id] = $values[$id];
            }
        }

        // I7: anything not in the definition is rejected, never silently dropped.
        foreach (array_keys(array_diff_key($input, $values)) as $unknown) {
            $errors[$unknown] = ['unknown_field'];
        }

        return new ValidationResult($errors === [], $errors, $errors === [] ? $data : []);
    }
}
