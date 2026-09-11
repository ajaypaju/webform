<?php

namespace App\Forms;

use LogicException;

final class DefinitionRules
{
    /**
     * Validate a form definition at save time. Semantics: conformance/README.md.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string> error codes, one per violation in field order; empty when valid
     */
    public function validate(array $definition): array
    {
        throw new LogicException('Not implemented: see conformance/definitions/*.json');
    }
}
