<?php

use Illuminate\Support\Facades\DB;

it('logs a user in with email and password, into their tenant, with a fresh session id', function () {
    $tenant = tenant();
    $user = user($tenant);

    $this->get('/login')->assertOk()->assertSee('With your account')->assertSee('With an API key');
    $before = $this->app['session']->getId();

    $this->post('/login', ['email' => strtoupper($user['email']), 'password' => $user['password']])->assertRedirect('/dashboard');

    expect(session('user_id'))->toBe($user['id'])->and(session('tenant_id'))->toBe($tenant)
        ->and($this->app['session']->getId())->not->toBe($before)
        ->and(json_encode(session()->all()))->not->toContain($user['password']);

    $this->get('/dashboard')->assertOk()->assertSee($user['email'])->assertSee('API keys');
    $this->post('/logout')->assertRedirect('/login');
    $this->get('/dashboard')->assertRedirect('/login');
});

it('answers a wrong password and an unknown email with the same message', function () {
    $user = user(tenant());

    $message = 'That email and password do not match.';
    $this->from('/login')->post('/login', ['email' => $user['email'], 'password' => 'not it, sorry'])->assertRedirect('/login')->assertSessionHasErrors(['login' => $message]);
    $this->from('/login')->post('/login', ['email' => 'nobody@example.com', 'password' => 'not it, sorry'])->assertRedirect('/login')->assertSessionHasErrors(['login' => $message]);
    expect(session()->has('user_id'))->toBeFalse();
});

// I11: membership is checked on every request, so a removed member is out immediately, not at their next login.
it('drops a session whose membership was removed, on the very next request', function () {
    $tenant = tenant();
    $user = user($tenant);
    $this->post('/login', ['email' => $user['email'], 'password' => $user['password']])->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertOk();

    DB::connection('pgsql')->table('tenant_users')->where('user_id', $user['id'])->delete();

    $this->get('/dashboard')->assertRedirect('/login')->assertSessionHasErrors('login');
    expect(session()->has('tenant_id'))->toBeFalse()->and(session()->has('user_id'))->toBeFalse();
    $this->getJson('/dashboard/api-keys')->assertStatus(401);

    // The user still exists and can still log in — into nothing.
    $this->from('/login')->post('/login', ['email' => $user['email'], 'password' => $user['password']])->assertRedirect('/login')->assertSessionHasErrors('login');
});
