<?php

namespace App\Http;

use RuntimeException;

// Rendered as 422 {"errors": [{"code": ..., "field"?: ...}]} by bootstrap/app.php.
final class ValidationFailed extends RuntimeException
{
    /** @param  list<array{code: string, field?: string}>  $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed: '.json_encode($errors));
    }

    /** @param  list<string>  $codes */
    public static function codes(array $codes): self
    {
        return new self(array_map(fn (string $code) => ['code' => $code], $codes));
    }
}
