<?php

namespace App\Http\Controllers\Dashboard;

use App\Accounts\Verification;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tenancy\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class VerificationController extends Controller
{
    /** The signed link from the log. The signature is the proof; no session is required to follow it. */
    public function verify(Request $request, string $user, string $hash): RedirectResponse
    {
        $account = User::query()->find($user);

        abort_if($account === null || ! hash_equals(Verification::hash($account->email), $hash), 403);

        if (! $account->verified()) {
            $account->forceFill(['email_verified_at' => now()])->save();
        }

        return $request->session()->has('tenant_id')
            ? redirect()->route('dashboard.forms')->with('status', 'Email address verified.')
            : redirect()->route('login')->with('status', 'Email address verified. Log in to continue.');
    }

    public function resend(Request $request): RedirectResponse
    {
        $membership = $request->attributes->get('membership');
        abort_unless($membership instanceof Membership, 403);

        $account = User::query()->findOrFail($membership->userId);

        if (! $account->verified()) {
            Verification::log($account);
        }

        return back()->with('status', 'A new verification link has been written to the api log.');
    }
}
