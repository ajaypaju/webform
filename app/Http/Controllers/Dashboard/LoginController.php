<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tenancy\ApiKey;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// Two ways into a tenant session: email + password (self-service accounts) or an API key (operator-provisioned
// tenants have no user). Both leave only ids in the session; both regenerate the session id first.
final class LoginController extends Controller
{
    // WHY: a bcrypt check runs whether or not the email exists, so timing does not say which it was.
    private const UNKNOWN_USER_HASH = '$2y$12$Sk9/b65TS/tqLbstBolzGOnLvu2rMGi5WjrStpC2mE3TCBsK4Fz/.';

    private const FAILED = 'That email and password do not match.';

    public function show(): View
    {
        return view('dashboard.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'string', 'email', 'max:254'], 'password' => ['required', 'string', 'max:1024']]);
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! Hash::check($credentials['password'], $user?->password_hash ?? self::UNKNOWN_USER_HASH) || $user === null) {
            return back()->withInput($request->only('email'))->withErrors(['login' => self::FAILED]);
        }

        $tenantId = DB::scalar('select resolve_user_tenants(?) limit 1', [$user->id]);

        if ($tenantId === null) {
            return back()->withInput($request->only('email'))->withErrors(['login' => self::FAILED]);
        }

        self::start($request, $tenantId, $user->id);

        return redirect()->route('dashboard.forms');
    }

    public function storeKey(Request $request): RedirectResponse
    {
        $key = (string) $request->validate(['api_key' => ['required', 'string', 'max:200']])['api_key'];
        $hash = ApiKey::hash($key);
        $tenantId = DB::scalar('select resolve_api_key(?)', [$hash]);

        if ($tenantId === null) {
            return back()->withErrors(['api_key' => 'That API key is not valid.']);
        }

        // WHY: the key's id (never the key) goes into the session so DashboardTenant can end the session once the key is revoked.
        self::start($request, $tenantId, null, DB::scalar('select resolve_api_key_id(?)', [$hash]));

        return redirect()->route('dashboard.forms');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** WHY: regenerate before storing anything (session fixation); keep ids only — never the key or the password. */
    public static function start(Request $request, string $tenantId, ?string $userId, ?string $apiKeyId = null): void
    {
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenantId);
        $userId === null ? $request->session()->forget('user_id') : $request->session()->put('user_id', $userId);
        $apiKeyId === null ? $request->session()->forget('api_key_id') : $request->session()->put('api_key_id', $apiKeyId);
    }
}
