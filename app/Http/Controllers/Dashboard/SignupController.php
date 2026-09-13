<?php

namespace App\Http\Controllers\Dashboard;

use App\Accounts\Signup;
use App\Accounts\Verification;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

final class SignupController extends Controller
{
    public function __construct(private Signup $signup) {}

    public function show(): View
    {
        return view('dashboard.signup');
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            // WHY: Password::uncompromised() calls haveibeenpwned over HTTP per signup; the api role must not depend on
            // an external service, so length is the only strength rule (bcrypt via the default hasher).
            'password' => ['required', 'string', Password::min(12)->max(1024)],
            'company_name' => ['required', 'string', 'max:200'],
        ]);

        // WHY: the same redirect and page for a new and an existing address, so the form cannot be used to check
        // who has an account; the existing owner is told by (logged) email instead.
        try {
            if (User::query()->where('email', $input['email'])->exists()) {
                Verification::logExisting($input['email']);
            } else {
                $created = $this->signup->run($input['email'], $input['password'], $input['company_name']);
                Verification::log($created['user']);
                LoginController::start($request, $created['tenant_id'], $created['user']->id);
            }
        } catch (UniqueConstraintViolationException) {
            Verification::logExisting($input['email']);
        }

        if (! $request->session()->has('user_id')) {
            $request->session()->regenerate();
        }

        return redirect()->route('signup.done')->with('signup_email', $input['email']);
    }

    public function done(Request $request): View|RedirectResponse
    {
        $email = $request->session()->get('signup_email');

        return is_string($email) ? view('dashboard.signup-done', ['email' => $email]) : redirect()->route('signup');
    }
}
