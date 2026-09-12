<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantTransaction;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// The session holds only tenant_id, never the API key: a dashboard XSS can act for one session until logout, not
// hold a long-lived tenant-wide bearer token forever. A bearer key is ignored here on purpose.
final class DashboardTenant
{
    public function __construct(private Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->session()->get('tenant_id');

        if (! is_string($tenantId)) {
            return $request->expectsJson()
                ? response()->json(['error' => 'unauthenticated'], 401)
                : redirect()->route('login');
        }

        return TenantTransaction::run($this->app, $tenantId, fn () => $next($request));
    }
}
