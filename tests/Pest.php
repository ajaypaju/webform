<?php

use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

// WHY: truncation, not a wrapping transaction — the RLS tests open one connection per role, and a transaction on
// the owner's connection would be invisible to them.
pest()->use(DatabaseTruncation::class)->in('Feature/Schema');

// Fixture helpers run on the default connection, which the test process opens as webform_owner.

function tenant(): string
{
    $id = (string) Str::uuid7();
    DB::table('tenants')->insert(['id' => $id, 'name' => "tenant {$id}"]);

    return $id;
}

/** @return array{form: string, version: ?string} */
function form(string $tenantId, string $status = 'published'): array
{
    $form = (string) Str::uuid7();
    DB::table('forms')->insert(['id' => $form, 'tenant_id' => $tenantId, 'name' => 'f', 'draft' => '{"fields":[]}', 'status' => 'draft']);

    $version = null;

    if ($status !== 'draft') {
        $version = (string) Str::uuid7();
        DB::table('form_versions')->insert([
            'id' => $version, 'form_id' => $form, 'tenant_id' => $tenantId, 'version_no' => 1,
            'definition' => '{"fields":[]}', 'published_at' => now(),
        ]);
    }

    DB::table('forms')->where('id', $form)->update(['status' => $status, 'current_version_id' => $version]);

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
