<?php

namespace App\Forms;

use LogicException;

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
        throw new LogicException('Not implemented: see conformance/submissions/*.json');
    }
}
