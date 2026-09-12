<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Tenancy\ApiKey;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LoginController extends Controller
{
    public function show(): View
    {
        return view('dashboard.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $key = (string) $request->validate(['api_key' => ['required', 'string', 'max:200']])['api_key'];
        $tenantId = DB::scalar('select resolve_api_key(?)', [ApiKey::hash($key)]);

        if ($tenantId === null) {
            return back()->withErrors(['api_key' => 'That API key is not valid.']);
        }

        // WHY: only the tenant id is kept; the key itself never lands in a cookie, localStorage or the session store.
        $request->session()->regenerate();
        $request->session()->put('tenant_id', $tenantId);

        return redirect()->route('dashboard.forms');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
