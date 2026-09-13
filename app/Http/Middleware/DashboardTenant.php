<?php

namespace App\Http\Middleware;

use App\Tenancy\Membership;
use App\Tenancy\TenantTransaction;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

// The session holds tenant_id and, for a password login, user_id — never a password or an API key: a dashboard XSS
// can act for one session until logout, not hold a long-lived credential. A bearer key is ignored here on purpose.
final class DashboardTenant
{
    public function __construct(private Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->session()->get('tenant_id');
        $userId = $request->session()->get('user_id');
        $apiKeyId = $request->session()->get('api_key_id');

        if (! is_string($tenantId)) {
            return $this->deny($request);
        }

        return TenantTransaction::run($this->app, $tenantId, function () use ($request, $next, $tenantId, $userId, $apiKeyId) {
            // A session started with a key lives exactly as long as the key: revoked → gone on the next request.
            // WHY: an explicit column — exists() selects *, and key_hash is not granted to this role.
            if (is_string($apiKeyId) && DB::table('api_keys')->where('id', $apiKeyId)->whereNull('revoked_at')->value('id') === null) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $this->deny($request, 'The API key this session was started with has been revoked.');
            }

            if (is_string($userId)) {
                // I11: membership is re-read on every request, inside the tenant transaction, so a removed member
                // loses the dashboard on their next request rather than at their next login.
                $row = DB::table('tenant_users')->join('users', 'users.id', '=', 'tenant_users.user_id')
                    ->where('tenant_users.tenant_id', $tenantId)->where('tenant_users.user_id', $userId)
                    ->first(['users.email', 'users.email_verified_at', 'tenant_users.role']);

                if ($row === null) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();

                    return $this->deny($request, 'Your access to that workspace has been removed.');
                }

                $request->attributes->set('membership', new Membership($userId, $row->email, $row->role, $row->email_verified_at !== null));
            }

            return $next($request);
        });
    }

    private function deny(Request $request, ?string $message = null): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $redirect = redirect()->route('login');

        return $message === null ? $redirect : $redirect->withErrors(['login' => $message]);
    }
}
