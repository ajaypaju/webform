<?php

namespace App\Http\Middleware;

use App\Tenancy\ApiKey;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateApiKey
{
    public function __construct(private Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $tenantId = $token === null ? null : DB::scalar('select resolve_api_key(?)', [ApiKey::hash($token)]);

        if ($tenantId === null) {
            // WHY: one body for "no key" and "wrong key", so the response doesn't say which keys exist.
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        // I11: scoped, not singleton — Octane resets scoped instances per request.
        $this->app->scoped(TenantContext::class, fn () => new TenantContext($tenantId));

        // I11: the whole request runs in one transaction with the tenant set for that transaction only, so RLS
        // sees the tenant on every query and nothing outlives the request on the reused connection.
        return DB::transaction(function () use ($request, $next, $tenantId) {
            DB::statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);

            return $next($request);
        });
    }
}
