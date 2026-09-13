<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Tenancy\ApiKey;
use App\Tenancy\Membership;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Key lifecycle for the tenant of the session. The role can read every column but key_hash (migration grants);
// a plaintext key exists only in the response that created it.
final class ApiKeyController extends Controller
{
    public function index(): View
    {
        return view('dashboard.api-keys', [
            'keys' => DB::table('api_keys')->orderByDesc('created_at')->orderByDesc('id')
                ->get(['id', 'prefix', 'created_at', 'last_used_at', 'revoked_at']),
        ]);
    }

    public function store(Request $request, TenantContext $tenant): RedirectResponse
    {
        abort_unless(self::mayManage($request), 403, 'Verify your email address to create API keys.');

        $key = ApiKey::generate();
        DB::table('api_keys')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenant->tenantId, 'key_hash' => ApiKey::hash($key), 'prefix' => ApiKey::prefix($key)]);

        return redirect()->route('dashboard.api-keys')->with('new_key', $key);
    }

    public function revoke(Request $request, string $key): RedirectResponse
    {
        abort_unless(self::mayManage($request), 403, 'Verify your email address to revoke API keys.');

        // WHY: lock the tenant's live keys so two concurrent revokes cannot both see "two left" and leave none.
        $live = DB::table('api_keys')->whereNull('revoked_at')->lockForUpdate()->pluck('id')->all();

        if (! in_array($key, $live, true)) {
            abort(404);
        }

        if (count($live) === 1) {
            return back()->withErrors(['revoke' => 'This is the last live key; create another before revoking it.']);
        }

        DB::table('api_keys')->where('id', $key)->update(['revoked_at' => now()]);

        return redirect()->route('dashboard.api-keys')->with('status', 'Key revoked. Requests with it now get 401.');
    }

    /** A password session must be verified; an API-key session (no user) was provisioned by an operator. */
    public static function mayManage(Request $request): bool
    {
        $membership = $request->attributes->get('membership');

        return ! $membership instanceof Membership || $membership->verified;
    }
}
