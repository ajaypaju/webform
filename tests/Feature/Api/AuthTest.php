<?php

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['api', 'api-key'])->get('/v1/whoami', fn (TenantContext $tenant) => [
        'tenant_id' => $tenant->tenantId,
        // Under RLS with the tenant set, the api role sees exactly this tenant's forms.
        'form_tenants' => DB::table('forms')->pluck('tenant_id')->unique()->values()->all(),
    ]);
});

it('returns 401 with the same body for a missing and a wrong key', function () {
    $missing = $this->getJson('/v1/whoami');
    $wrong = $this->withToken('wf_nope')->getJson('/v1/whoami');

    $missing->assertStatus(401);
    $wrong->assertStatus(401);
    expect($wrong->json())->toBe($missing->json())->toBe(['error' => 'unauthenticated']);
});

// I11
it('binds the tenant of the key and sets it for the request transaction', function () {
    $a = tenant();
    form($a);
    form(tenant());

    $this->withToken(apiKey($a))->getJson('/v1/whoami')
        ->assertOk()
        ->assertExactJson(['tenant_id' => $a, 'form_tenants' => [$a]]);
});

// I11: two tenants on one process — the second request must not see the first request's binding or tenant.
it('rebinds the tenant per request, so consecutive requests with different keys see only their own data', function () {
    [$a, $b] = [tenant(), tenant()];
    form($a);
    form($b);
    [$keyA, $keyB] = [apiKey($a), apiKey($b)];

    $this->withToken($keyA)->getJson('/v1/whoami')->assertExactJson(['tenant_id' => $a, 'form_tenants' => [$a]]);
    $this->withToken($keyB)->getJson('/v1/whoami')->assertExactJson(['tenant_id' => $b, 'form_tenants' => [$b]]);
    $this->withToken($keyA)->getJson('/v1/whoami')->assertExactJson(['tenant_id' => $a, 'form_tenants' => [$a]]);

    expect(app(TenantContext::class)->tenantId)->toBe($a);
});

it('sees zero rows outside the request transaction (fail closed)', function () {
    form(tenant());

    expect(DB::table('forms')->count())->toBe(0);
});
