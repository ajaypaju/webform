<?php

use Illuminate\Support\Facades\DB;

it('rejects a bad key, and never stores anything about it', function () {
    // WHY: the CSRF token travels in the page, so no JS-readable XSRF-TOKEN cookie is issued — only the httpOnly session.
    $this->get('/login')->assertOk()->assertSee('API key')->assertCookieMissing('XSRF-TOKEN')->assertCookie(config('session.cookie'));

    $this->from('/login')->post('/login/key', ['api_key' => 'wf_nope'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('api_key')
        ->assertSessionMissing('tenant_id');

    $this->get('/dashboard')->assertRedirect('/login');
});

it('creates a tenant-only session from a valid key, regenerating the session id, and logout ends it', function () {
    $tenant = tenant();
    $key = apiKey($tenant);

    $this->get('/login');
    $before = $this->app['session']->getId();

    $this->post('/login/key', ['api_key' => $key])->assertRedirect('/dashboard');

    expect(session('tenant_id'))->toBe($tenant)
        ->and($this->app['session']->getId())->not->toBe($before)
        // WHY: the key itself must never be in the session — that is the whole point of the login design.
        ->and(json_encode(session()->all()))->not->toContain($key);

    $this->get('/dashboard')->assertOk()->assertSee('Forms');

    $this->post('/logout')->assertRedirect('/login');
    expect(session()->has('tenant_id'))->toBeFalse();
    $this->get('/dashboard')->assertRedirect('/login');
});

it('rate-limits login attempts per ip', function () {
    // WHY: one bucket for both forms, so alternating between them buys nothing.
    foreach (range(1, 5) as $i) {
        $i % 2 ? $this->post('/login/key', ['api_key' => "wf_wrong_{$i}"])->assertRedirect()
               : $this->post('/login', ['email' => "x{$i}@example.com", 'password' => 'wrong password 123'])->assertRedirect();
    }

    $this->post('/login', ['email' => 'x@example.com', 'password' => 'wrong password 123'])->assertStatus(429);
    $this->post('/login/key', ['api_key' => 'wf_wrong_6'])->assertStatus(429);
});
