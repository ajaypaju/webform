<?php

namespace App\Ingest;

use RuntimeException;
use Throwable;

// Rendered as 503 + Retry-After by bootstrap/app.php: the answer exists but nothing reachable can give it.
final class StoreUnavailable extends RuntimeException
{
    public function __construct(string $what, ?Throwable $previous = null)
    {
        parent::__construct("Version store: {$what} is unavailable and nothing is cached.", 0, $previous);
    }
}
