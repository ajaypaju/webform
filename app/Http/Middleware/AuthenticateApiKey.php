<?php

namespace App\Http\Middleware;

use App\Tenancy\ApiKey;
use App\Tenancy\TenantTransaction;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

// Stateless: bearer key only, never a cookie. A dashboard session can't reach /v1 (no session middleware here).
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

        return TenantTransaction::run($this->app, $tenantId, fn () => $next($request));
    }
}
