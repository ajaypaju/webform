<?php

use App\Database\RuntimeRole;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// I11
it('accepts each runtime role on its own connection', function () {
    RuntimeRole::assertForAppRole(DB::connection('pgsql_api'), 'api');
    RuntimeRole::assertForAppRole(DB::connection('pgsql_ingest'), 'ingest');
    RuntimeRole::assertForAppRole(DB::connection('pgsql_writer'), 'consumer');

    expect(true)->toBeTrue();
});

it('refuses a process whose connection is the wrong role', function () {
    expect(fn () => RuntimeRole::assertForAppRole(DB::connection('pgsql_ingest'), 'api'))
        ->toThrow(RuntimeException::class, 'is webform_ingest; this process must connect as webform_api');
});

it('refuses the owner role even when it is the expected name, because it owns the tables', function () {
    expect(fn () => RuntimeRole::assert(DB::connection(), 'webform_owner'))
        ->toThrow(RuntimeException::class, 'webform_owner is owns_tables');

    expect(fn () => RuntimeRole::assertForAppRole(DB::connection(), 'api'))
        ->toThrow(RuntimeException::class, 'is webform_owner; this process must connect as webform_api');
});

it('refuses an APP_ROLE it does not know', function () {
    expect(fn () => RuntimeRole::assertForAppRole(DB::connection('pgsql_api'), 'worker'))
        ->toThrow(RuntimeException::class, 'APP_ROLE "worker" has no database role');
});

// I11 + I12: ingest checks on the first connection instead of at boot.
it('lets ingest boot with PostgreSQL unreachable and fails only when it first connects', function () {
    config(['app.role' => 'ingest', 'database.connections.pgsql_down' => [...config('database.connections.pgsql'), 'host' => '127.0.0.1', 'port' => 9]]);

    (new App\Providers\AppServiceProvider($this->app))->boot();

    expect(fn () => DB::connection('pgsql_down')->select('select 1'))->toThrow(QueryException::class, 'Connection refused');
});

it('refuses an ingest connection of the wrong role before its first query runs, and re-checks the next one', function () {
    RuntimeRole::checkOnFirstConnection($this->app['events'], $this->app['db'], 'ingest');
    DB::purge('pgsql_writer');

    $insert = fn () => DB::connection('pgsql_writer')->insert('insert into submission_ids (id, received_at) values (?, now())', [(string) Str::uuid7()]);

    expect($insert)->toThrow(RuntimeException::class, 'is webform_writer; this process must connect as webform_ingest')
        ->and($insert)->toThrow(RuntimeException::class, 'must connect as webform_ingest')
        ->and(DB::table('submission_ids')->count())->toBe(0);

    DB::purge('pgsql_ingest');
    expect(DB::connection('pgsql_ingest')->scalar('select current_user'))->toBe('webform_ingest');
});
