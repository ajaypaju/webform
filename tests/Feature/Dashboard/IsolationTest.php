<?php

// I11: two credentials, two surfaces, never crossed.
it('never lets a session cookie authenticate the stateless /v1 api', function () {
    $tenant = tenant();
    form($tenant);

    $this->withSession(['tenant_id' => $tenant])->getJson('/v1/forms')->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
});

it('never lets an api key authenticate a dashboard route', function () {
    $key = apiKey(tenant());

    $this->withToken($key)->get('/dashboard')->assertRedirect('/login');
    $this->withToken($key)->getJson('/dashboard')->assertStatus(401)->assertExactJson(['error' => 'unauthenticated']);
});

it("returns 404 for another tenant's form from a session", function () {
    ['form' => $formB] = form(tenant());
    $a = tenant();

    $this->withSession(['tenant_id' => $a])->get("/dashboard/forms/{$formB}")->assertNotFound();
    $this->withSession(['tenant_id' => $a])->putJson("/dashboard/forms/{$formB}/draft", ['definition' => ['fields' => []]])->assertNotFound();
    $this->withSession(['tenant_id' => $a])->postJson("/dashboard/forms/{$formB}/publish")->assertNotFound();
});

it('refuses a mutating dashboard request without a CSRF token (419) and accepts one with it', function () {
    // WHY: Laravel skips CSRF checks while the app env is "testing"; this test wants the real behaviour.
    $this->app['env'] = 'local';
    $tenant = tenant();

    $this->withSession(['tenant_id' => $tenant])->post('/dashboard/forms', ['name' => 'x'])->assertStatus(419);

    $this->withSession(['tenant_id' => $tenant])->get('/dashboard')->assertOk();
    $this->withSession(['tenant_id' => $tenant])->post('/dashboard/forms', ['name' => 'x', '_token' => csrf_token()])->assertRedirect();
    $this->withSession(['tenant_id' => $tenant])->withHeaders(['X-CSRF-TOKEN' => csrf_token()])->putJson('/dashboard/forms/'.Illuminate\Support\Str::uuid7().'/draft', ['definition' => []])->assertNotFound();

    $this->app['env'] = 'testing';
});

it('sends the dashboard with a non-embeddable CSP and cookie-free public pages stay untouched', function () {
    $this->withSession(['tenant_id' => tenant()])->get('/dashboard')
        ->assertHeader('Content-Security-Policy', str_replace('frame-ancestors *', "frame-ancestors 'none'", App\Http\Middleware\PublicHeaders::CSP))
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});
