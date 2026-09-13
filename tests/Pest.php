<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use App\Ingest\RenderToken;
use Illuminate\Support\Str;
use Tests\IngestTestCase;
use Tests\TestCase;

// WHY: Feature/Ingest boots with APP_ROLE=ingest, so the api-role folders are listed instead of all of Feature.
pest()->extend(TestCase::class)->in('Feature/Api', 'Feature/Console', 'Feature/Consumer', 'Feature/Dashboard', 'Feature/Kafka', 'Feature/Schema', 'Feature/HealthTest.php');
pest()->extend(IngestTestCase::class)->in('Feature/Ingest');

// WHY: truncation, not a wrapping transaction — the RLS tests open one connection per role, and a transaction on
// the owner's connection would be invisible to them.
pest()->use(DatabaseTruncation::class)->in('Feature/Schema', 'Feature/Api', 'Feature/Console', 'Feature/Consumer', 'Feature/Dashboard', 'Feature/Ingest');

// WHY: HTTP tests exercise the real api role (RLS included). Truncation and migrations already ran on the owner
// connection in setUp; only the request path switches.
pest()->beforeEach(fn () => config(['database.default' => 'pgsql_api']))->in('Feature/Api', 'Feature/Dashboard');
pest()->beforeEach(function () {
    config(['database.default' => 'pgsql_ingest']);
    Redis::flushdb();
})->in('Feature/Ingest');

// Fixture helpers run on the owner connection ('pgsql', which the test process opens as webform_owner).

function tenant(): string
{
    $id = (string) Str::uuid7();
    DB::connection('pgsql')->table('tenants')->insert(['id' => $id, 'name' => "tenant {$id}"]);

    return $id;
}

/** @return array{form: string, version: ?string} */
function form(string $tenantId, string $status = 'published', array $definition = ['fields' => []]): array
{
    $form = (string) Str::uuid7();
    DB::connection('pgsql')->table('forms')->insert(['id' => $form, 'tenant_id' => $tenantId, 'name' => 'f', 'draft' => '{"fields":[]}', 'status' => 'draft']);

    $version = null;

    if ($status !== 'draft') {
        $version = (string) Str::uuid7();
        DB::connection('pgsql')->table('form_versions')->insert([
            'id' => $version, 'form_id' => $form, 'tenant_id' => $tenantId, 'version_no' => 1,
            'definition' => json_encode($definition), 'published_at' => now(),
        ]);
    }

    DB::connection('pgsql')->table('forms')->where('id', $form)->update(['status' => $status, 'current_version_id' => $version]);

    return ['form' => $form, 'version' => $version];
}

/** @return array<string, mixed> a submissions row for the given form/version */
function submission(string $tenantId, string $formId, string $versionId, ?string $receivedAt = null): array
{
    return [
        'id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'form_id' => $formId, 'form_version_id' => $versionId,
        'data' => '{}', 'meta' => '{}', 'received_at' => $receivedAt ?? now(),
    ];
}

/** Run $callback inside a transaction on $connection with app.tenant_id set for that transaction only (I11). */
function asTenant(string $connection, string $tenantId, Closure $callback): mixed
{
    return DB::connection($connection)->transaction(function (ConnectionInterface $db) use ($tenantId, $callback) {
        $db->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);

        return $callback($db);
    });
}

/** Point a PostgreSQL connection at a closed port and drop the cached connection. */
function postgresDown(string $connection = 'pgsql_ingest'): void
{
    config(["database.connections.{$connection}" => [...config("database.connections.{$connection}"), 'host' => '127.0.0.1', 'port' => 9]]);
    DB::purge($connection);
}

/** Point the default Redis connection at a closed port. RedisManager copies its config at construction, so rebuild it. */
function redisDown(): void
{
    config(['database.redis.default' => [...config('database.redis.default'), 'host' => '127.0.0.1', 'port' => 9]]);
    app()->forgetInstance('redis');
    app()->forgetInstance('redis.connection');
    Redis::clearResolvedInstance('redis');
}

/** Create an API key for the tenant (as owner) and return the plaintext to send as a Bearer token. */
function apiKey(string $tenantId): string
{
    $key = 'wf_test_'.Str::random(32);
    DB::connection('pgsql')->table('api_keys')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'key_hash' => hash('sha256', $key)]);

    return $key;
}

/** A user who owns $tenantId; password is 'correct horse battery'. @return array{id: string, email: string, password: string} */
function user(string $tenantId, bool $verified = true): array
{
    $id = (string) Str::uuid7();
    $email = "user-{$id}@example.com";
    DB::connection('pgsql')->table('users')->insert([
        'id' => $id, 'email' => $email, 'password_hash' => Illuminate\Support\Facades\Hash::make('correct horse battery'),
        'email_verified_at' => $verified ? now() : null,
    ]);
    DB::connection('pgsql')->table('tenant_users')->insert(['tenant_id' => $tenantId, 'user_id' => $id, 'role' => 'owner']);

    return ['id' => $id, 'email' => $email, 'password' => 'correct horse battery'];
}

// --- Ingest request helpers (tests/Feature/Ingest) -------------------------------------------------

const IP = '203.0.113.9';

function fields(): array
{
    return [
        ['id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
        ['id' => 'plan', 'type' => 'select', 'label' => 'Plan', 'required' => false, 'options' => [['value' => 'free', 'label' => 'Free'], ['value' => 'pro', 'label' => 'Pro']]],
        ['id' => 'seats', 'type' => 'number', 'label' => 'Seats', 'required' => false, 'rules' => ['integer' => true, 'min' => 1],
            'visible_if' => ['field' => 'plan', 'op' => 'eq', 'value' => 'pro']],
        ['id' => 'code', 'type' => 'text', 'label' => 'Code', 'required' => false, 'rules' => ['pattern' => '^[A-Z]{3}$']],
    ];
}

/** A published form with fields(); returns [form, version]. */
function liveForm(): array
{
    return form(tenant(), 'published', ['fields' => fields()]);
}

function payload(string $form, string $version, array $data, int $issuedAgo = 5, array $extra = []): array
{
    return [
        'submission_id' => (string) Str::uuid7(),
        'form_version_id' => $version,
        'render_token' => app(RenderToken::class)->issue($form, $version, now()->timestamp - $issuedAgo),
        'data' => $data,
    ] + $extra;
}

function submit(string $form, array $body)
{
    return test()->withServerVariables(['REMOTE_ADDR' => IP])
        ->withHeaders(['User-Agent' => 'PestBrowser/1.0', 'Referer' => 'https://customer.example/pricing?x=1'])
        ->postJson("/v1/forms/{$form}/submissions", $body);
}

/** Insert a stored submission as the owner; $receivedAt as a full timestamp string so ties can be forced. */
function storeRow(string $tenant, string $form, string $version, array $data, string $receivedAt): string
{
    $id = (string) Str::uuid7();
    DB::connection('pgsql')->table('submissions')->insert([
        'id' => $id, 'tenant_id' => $tenant, 'form_id' => $form, 'form_version_id' => $version,
        'data' => json_encode($data), 'meta' => '{}', 'received_at' => $receivedAt,
    ]);

    return $id;
}
