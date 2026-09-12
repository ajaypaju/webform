<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;

// I11: the one way a request becomes tenant-scoped, whether the tenant came from a bearer key or a session.
final class TenantTransaction
{
    /** Bind the tenant for this request only, then run $callback inside a transaction with the tenant set for it. */
    public static function run(Application $app, string $tenantId, Closure $callback): mixed
    {
        // I11: scoped, not singleton — Octane resets scoped instances per request.
        $app->scoped(TenantContext::class, fn () => new TenantContext($tenantId));

        // I11: the whole request runs in one transaction with the tenant set for that transaction only, so RLS
        // sees the tenant on every query and nothing outlives the request on the reused connection.
        return DB::transaction(function () use ($tenantId, $callback) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);

            return $callback();
        });
    }
}
