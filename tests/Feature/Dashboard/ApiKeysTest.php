<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tenant = tenant();
    $this->user = user($this->tenant);
    $this->session = ['tenant_id' => $this->tenant, 'user_id' => $this->user['id']];
    $this->first = apiKey($this->tenant);
});

it('creates a key shown once, lists keys by prefix with last use, and never shows a hash', function () {
    $this->withSession($this->session)->post('/dashboard/api-keys')->assertRedirect('/dashboard/api-keys');
    $html = $this->withSession($this->session)->get('/dashboard/api-keys')->assertOk()->getContent();

    preg_match('#<code class="key">(wf_[A-Za-z0-9]+)</code>#', $html, $m);
    $key = $m[1];
    expect($key)->not->toBeNull()->and(strlen($key))->toBeGreaterThan(40)
        ->and($html)->toContain(substr($key, 0, 8).'…')->toContain('never')
        ->and($this->withSession($this->session)->get('/dashboard/api-keys')->getContent())->not->toContain($key);

    // The new key works on /v1 and its use is recorded; the hash never appears anywhere in the page.
    $this->withToken($key)->getJson('/v1/forms')->assertOk();
    $html = $this->withSession($this->session)->get('/dashboard/api-keys')->getContent();
    expect(substr_count($html, 'never'))->toBe(1)->and($html)->not->toContain(hash('sha256', $key))->not->toContain(hash('sha256', $this->first));
});

it('revokes a key so /v1 answers 401 on the next request, but never the last live key', function () {
    $this->withToken($this->first)->getJson('/v1/forms')->assertOk();
    $firstId = DB::connection('pgsql')->table('api_keys')->value('id');

    $this->withSession($this->session)->from('/dashboard/api-keys')->post("/dashboard/api-keys/{$firstId}/revoke")
        ->assertRedirect('/dashboard/api-keys')->assertSessionHasErrors('revoke');
    $this->withToken($this->first)->getJson('/v1/forms')->assertOk();

    $this->withSession($this->session)->post('/dashboard/api-keys')->assertRedirect();
    $this->withSession($this->session)->post("/dashboard/api-keys/{$firstId}/revoke")->assertRedirect('/dashboard/api-keys')->assertSessionHasNoErrors();

    $this->withToken($this->first)->getJson('/v1/forms')->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
    $this->post('/login/key', ['api_key' => $this->first])->assertSessionHasErrors('api_key');
    expect(DB::connection('pgsql')->table('api_keys')->where('id', $firstId)->value('revoked_at'))->not->toBeNull();
    $this->withSession($this->session)->get('/dashboard/api-keys')->assertOk()->assertSee('revoked');
});

it("cannot see or revoke another tenant's keys", function () {
    $b = tenant();
    apiKey($b);
    $keyB = DB::connection('pgsql')->table('api_keys')->where('tenant_id', $b)->value('id');

    $html = $this->withSession($this->session)->get('/dashboard/api-keys')->getContent();
    expect(substr_count($html, 'Revoke'))->toBe(1);
    $this->withSession($this->session)->post("/dashboard/api-keys/{$keyB}/revoke")->assertNotFound();
    expect(DB::connection('pgsql')->table('api_keys')->where('id', $keyB)->value('revoked_at'))->toBeNull();
});

it('keeps operator-provisioned tenants working: key login manages keys without a user', function () {
    $this->post('/login/key', ['api_key' => $this->first])->assertRedirect('/dashboard');
    expect(session()->has('user_id'))->toBeFalse()
        ->and(session('api_key_id'))->toBe(DB::connection('pgsql')->table('api_keys')->value('id'))
        ->and(json_encode(session()->all()))->not->toContain($this->first);
    $this->get('/dashboard/api-keys')->assertOk()->assertDontSee('not verified');
    $this->post('/dashboard/api-keys')->assertRedirect('/dashboard/api-keys');
    expect(DB::connection('pgsql')->table('api_keys')->where('tenant_id', $this->tenant)->count())->toBe(2);
});

// A leaked key is dead in one click: /v1 refuses it, and so does the dashboard session that was started with it.
it('ends a session started with a key on the next request after that key is revoked', function () {
    $this->post('/login/key', ['api_key' => $this->first])->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertOk();

    apiKey($this->tenant);
    DB::connection('pgsql')->table('api_keys')->where('key_hash', hash('sha256', $this->first))->update(['revoked_at' => now()]);

    $this->get('/dashboard')->assertRedirect('/login')->assertSessionHasErrors('login');
    expect(session()->has('tenant_id'))->toBeFalse()->and(session()->has('api_key_id'))->toBeFalse();
    $this->post('/login/key', ['api_key' => $this->first])->assertSessionHasErrors('api_key');
});
