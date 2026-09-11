<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// I11
it('lets the ingest role read published forms and their versions of any tenant, and nothing else', function () {
    ['form' => $published, 'version' => $version] = form(tenant());
    ['form' => $draft] = form(tenant(), 'draft');
    ['form' => $archived, 'version' => $archivedVersion] = form(tenant(), 'archived');

    $db = DB::connection('pgsql_ingest');

    expect($db->table('forms')->pluck('id')->all())->toBe([$published])
        ->and($db->table('form_versions')->pluck('id')->all())->toBe([$version])
        ->and($db->table('forms')->where('id', $draft)->pluck('id')->all())->toBe([])
        ->and($db->table('form_versions')->where('id', $archivedVersion)->pluck('id')->all())->toBe([]);

    // Column privileges: the draft (and everything else on forms) is not the public path's business.
    foreach (['draft', 'name'] as $column) {
        expect(fn () => $db->table('forms')->pluck($column))->toThrow(QueryException::class, 'permission denied');
    }

    foreach (['submissions', 'submission_ids', 'api_keys', 'tenants'] as $table) {
        expect(fn () => $db->table($table)->count())->toThrow(QueryException::class, 'permission denied');
    }
});
