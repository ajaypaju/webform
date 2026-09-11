<?php

use App\Tenancy\ApiKey;
use Illuminate\Support\Facades\DB;

// Runs as the owner role, like `make tenant` does.
it('creates a tenant with a key that is printed once and stored only as a hash', function () {
    $this->artisan('tenants:create', ['name' => 'Acme'])->expectsOutputToContain('api_key:   wf_')->assertExitCode(0);

    $tenant = DB::table('tenants')->where('name', 'Acme')->first();
    $stored = DB::table('api_keys')->where('tenant_id', $tenant->id)->value('key_hash');

    expect($stored)->toMatch('/^[0-9a-f]{64}$/')
        ->and(ApiKey::generate())->toMatch('/^wf_[0-9A-Za-z]{40,44}$/')
        ->and(ApiKey::generate())->not->toBe(ApiKey::generate());
});
