<?php

namespace App\Console\Commands;

use App\Tenancy\ApiKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantsCreate extends Command
{
    protected $signature = 'tenants:create {name : Tenant name}';

    protected $description = 'Create a tenant and its first API key; the key is printed once and stored only as a hash';

    public function handle(): int
    {
        $tenantId = (string) Str::uuid7();
        $key = ApiKey::generate();

        DB::transaction(function () use ($tenantId, $key) {
            DB::table('tenants')->insert(['id' => $tenantId, 'name' => $this->argument('name')]);
            DB::table('api_keys')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'key_hash' => ApiKey::hash($key)]);
        });

        $this->line("tenant_id: {$tenantId}");
        $this->line("api_key:   {$key}");
        $this->comment('Store the key now; it cannot be shown again.');

        return self::SUCCESS;
    }
}
