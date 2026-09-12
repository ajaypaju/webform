<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// I3
it('rejects a submission that points at another form’s or tenant’s version', function () {
    $a = tenant();
    ['form' => $form1] = form($a);
    ['version' => $version2] = form($a);
    ['version' => $versionOtherTenant] = form(tenant());

    expect(fn () => DB::table('submissions')->insert(submission($a, $form1, $version2)))
        ->toThrow(QueryException::class, 'violates foreign key constraint')
        ->and(fn () => DB::table('submissions')->insert(submission($a, $form1, $versionOtherTenant)))
        ->toThrow(QueryException::class, 'violates foreign key constraint');
});

it('lands a row outside every monthly partition in the DEFAULT partition', function () {
    $a = tenant();
    ['form' => $form, 'version' => $version] = form($a);
    $row = submission($a, $form, $version, '2031-06-15 12:00:00+00');

    DB::table('submissions')->insert($row);

    expect(DB::scalar('select tableoid::regclass::text from submissions where id = ?', [$row['id']]))
        ->toBe('submissions_default');
});

it('creates the current and upcoming monthly partitions idempotently', function () {
    $count = fn () => (int) DB::scalar("select count(*) from pg_inherits where inhparent = 'submissions'::regclass");
    $before = $count();

    $this->artisan('submissions:ensure-partitions')->assertExitCode(0);
    $after = $count();
    $this->artisan('submissions:ensure-partitions')->assertExitCode(0);

    // WHY: partitions are tables and survive truncation, so another test may already have created this month's.
    $expected = collect(range(0, 2))->map(fn ($i) => 'submissions_'.now('UTC')->startOfMonth()->addMonths($i)->format('Y_m'));
    expect($after)->toBeGreaterThanOrEqual($before)
        ->and($count())->toBe($after)
        ->and($expected->every(fn ($name) => DB::scalar('select to_regclass(?) is not null', [$name])))->toBeTrue();

    $a = tenant();
    ['form' => $form, 'version' => $version] = form($a);
    $row = submission($a, $form, $version);
    DB::table('submissions')->insert($row);

    expect(DB::scalar('select tableoid::regclass::text from submissions where id = ?', [$row['id']]))
        ->toBe('submissions_'.now('UTC')->format('Y_m'));
});
