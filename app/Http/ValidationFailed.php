<?php

namespace App\Http;

use RuntimeException;

// Rendered as {"errors": [{"code": ..., "field"?: ...}]} with $status (422 unless given) by bootstrap/app.php.
final class ValidationFailed extends RuntimeException
{
    /** @param  list<array{code: string, field?: string}>  $errors */
    public function __construct(public readonly array $errors, public readonly int $status = 422)
    {
        parent::__construct('Validation failed: '.json_encode($errors));
    }

    /** @param  list<string>  $codes */
    public static function codes(array $codes): self
    {
        return new self(array_map(fn (string $code) => ['code' => $code], $codes));
    }
}
