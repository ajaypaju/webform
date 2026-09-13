<?php

namespace App\Accounts;

use App\Models\User;
use App\Tenancy\ApiKey;
use App\Tenancy\TenantTransaction;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// One transaction: user, tenant, owner membership, first API key. Runs inside TenantTransaction for the tenant it
// is creating, so the api role's own policies (tenants.id / tenant_users.tenant_id = app.tenant_id) admit exactly
// these rows and nothing else. Any failure rolls all four back.
final class Signup
{
    public function __construct(private Application $app) {}

    /** @return array{user: User, tenant_id: string, api_key: string} the key in plaintext, shown once */
    public function run(string $email, string $password, string $companyName): array
    {
        $tenantId = (string) Str::uuid7();
        $key = ApiKey::generate();

        $user = TenantTransaction::run($this->app, $tenantId, function () use ($email, $password, $companyName, $tenantId, $key) {
            $user = User::create(['id' => (string) Str::uuid7(), 'email' => $email, 'password_hash' => Hash::make($password)]);
            DB::table('tenants')->insert(['id' => $tenantId, 'name' => $companyName]);
            DB::table('tenant_users')->insert(['tenant_id' => $tenantId, 'user_id' => $user->id, 'role' => 'owner']);
            DB::table('api_keys')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'key_hash' => ApiKey::hash($key), 'prefix' => ApiKey::prefix($key)]);

            return $user;
        });

        return ['user' => $user, 'tenant_id' => $tenantId, 'api_key' => $key];
    }
}
