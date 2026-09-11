<?php

namespace App\Tenancy;

// I11: bound per request with app()->scoped() by AuthenticateApiKey; Octane forgets scoped instances between requests.
final readonly class TenantContext
{
    public function __construct(public string $tenantId) {}
}
