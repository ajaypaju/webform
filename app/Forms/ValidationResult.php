<?php

namespace App\Forms;

final readonly class ValidationResult
{
    /**
     * @param  array<string, list<string>>  $errors  field id => error codes (conformance/README.md)
     * @param  array<string, mixed>  $data  exactly what gets stored: validated, visible fields only, in definition order
     */
    public function __construct(
        public bool $valid,
        public array $errors,
        public array $data,
    ) {}
}
