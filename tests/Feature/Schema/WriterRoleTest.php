<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// I11
it('lets the writer role insert submissions for any tenant but never read them', function () {
    [$a, $b] = [tenant(), tenant()];
    ['form' => $formA, 'version' => $versionA] = form($a);
    ['form' => $formB, 'version' => $versionB] = form($b);

    $db = DB::connection('pgsql_writer');
    $db->table('submissions')->insert([submission($a, $formA, $versionA), submission($b, $formB, $versionB)]);

    expect(DB::table('submissions')->pluck('tenant_id')->sort()->values()->all())->toBe(collect([$a, $b])->sort()->values()->all());

    expect(fn () => $db->table('submissions')->count())->toThrow(QueryException::class, 'permission denied');
    expect(fn () => $db->table('forms')->count())->toThrow(QueryException::class, 'permission denied');
});

// I2
it('returns only new ids from submission_ids ON CONFLICT DO NOTHING', function () {
    $db = DB::connection('pgsql_writer');
    [$x, $y, $z] = [(string) Str::uuid7(), (string) Str::uuid7(), (string) Str::uuid7()];
    $sql = 'insert into submission_ids (id, received_at) values (?, now()), (?, now()) on conflict (id) do nothing returning id';

    $first = array_column($db->select($sql, [$x, $y]), 'id');
    $second = array_column($db->select($sql, [$y, $z]), 'id');

    expect($first)->toBe([$x, $y])
        ->and($second)->toBe([$z]);

    expect(fn () => $db->table('submission_ids')->pluck('received_at'))->toThrow(QueryException::class, 'permission denied');
});

it('lets the writer role keep partitions ahead through the definer function, but not create tables itself', function () {
    $db = DB::connection('pgsql_writer');

    expect(array_column($db->select('select ensure_submission_partitions(4) as name'), 'name'))->toHaveCount(4)
        ->and($db->scalar("select count(*) from pg_inherits where inhparent = 'submissions'::regclass"))->toBeGreaterThanOrEqual(5);

    expect(fn () => $db->statement('create table submissions_2099_01 partition of submissions for values from (\'2099-01-01\') to (\'2099-02-01\')'))
        ->toThrow(QueryException::class, 'permission denied');
    expect(fn () => DB::connection('pgsql_ingest')->select('select ensure_submission_partitions(1)'))
        ->toThrow(QueryException::class, 'permission denied');
});
