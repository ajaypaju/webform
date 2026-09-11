<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// I4
it('rejects UPDATE and DELETE on form_versions, even as the owner', function () {
    ['version' => $version] = form(tenant());

    expect(fn () => DB::table('form_versions')->where('id', $version)->update(['version_no' => 2]))
        ->toThrow(QueryException::class, 'form_versions is immutable (UPDATE)')
        ->and(fn () => DB::table('form_versions')->where('id', $version)->delete())
        ->toThrow(QueryException::class, 'form_versions is immutable (DELETE)');

    expect(DB::table('form_versions')->where('id', $version)->value('version_no'))->toBe(1);
});
