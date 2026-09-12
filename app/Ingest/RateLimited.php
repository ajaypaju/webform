<?php

namespace App\Ingest;

use RuntimeException;

// Rendered as 429 + Retry-After by bootstrap/app.php.
final class RateLimited extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct("Rate limited; retry after {$retryAfter}s.");
    }
}
