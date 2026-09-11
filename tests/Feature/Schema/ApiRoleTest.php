<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// I11
it('scopes the api role to the tenant set for the transaction', function () {
    [$a, $b] = [tenant(), tenant()];
    ['form' => $formA, 'version' => $versionA] = form($a);
    ['form' => $formB, 'version' => $versionB] = form($b);
    DB::table('submissions')->insert([submission($a, $formA, $versionA), submission($b, $formB, $versionB)]);

    $seen = asTenant('pgsql_api', $a, fn ($db) => [
        'forms' => $db->table('forms')->pluck('tenant_id')->unique()->all(),
        'form_versions' => $db->table('form_versions')->pluck('tenant_id')->unique()->all(),
        'submissions' => $db->table('submissions')->pluck('tenant_id')->unique()->all(),
    ]);

    expect($seen)->toBe(['forms' => [$a], 'form_versions' => [$a], 'submissions' => [$a]]);
});

it('returns zero rows to the api role when no tenant is set', function () {
    $a = tenant();
    ['form' => $form, 'version' => $version] = form($a);
    DB::table('submissions')->insert(submission($a, $form, $version));

    $db = DB::connection('pgsql_api');

    expect($db->table('forms')->count())->toBe(0)
        ->and($db->table('form_versions')->count())->toBe(0)
        ->and($db->table('submissions')->count())->toBe(0);
});

it('rejects an api insert that names another tenant', function () {
    [$a, $b] = [tenant(), tenant()];

    expect(fn () => asTenant('pgsql_api', $a, fn ($db) => $db->table('forms')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => $b, 'name' => 'x', 'draft' => '{}', 'status' => 'draft',
    ])))->toThrow(QueryException::class, 'row-level security policy');

    expect(DB::table('forms')->where('tenant_id', $b)->count())->toBe(0);
});

it('denies the api role direct access to api_keys but resolves a key hash to a tenant', function () {
    $a = tenant();
    $hash = hash('sha256', 'secret');
    DB::table('api_keys')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $a, 'key_hash' => $hash]);

    $db = DB::connection('pgsql_api');

    expect(fn () => $db->table('api_keys')->count())->toThrow(QueryException::class, 'permission denied');
    expect($db->scalar('select resolve_api_key(?)', [$hash]))->toBe($a)
        ->and($db->scalar('select resolve_api_key(?)', ['nope']))->toBeNull();

    expect(fn () => DB::connection('pgsql_ingest')->scalar('select resolve_api_key(?)', [$hash]))
        ->toThrow(QueryException::class, 'permission denied');
});
