<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->guardTestDatabase();
    }

    // WHY: inside the api container the runtime env (webform, local) shadows phpunit.xml, so a plain
    // `php artisan test` would run migrations and truncations against the live database. Abort instead.
    private function guardTestDatabase(): void
    {
        $env = config('app.env');
        $database = DB::connection()->getDatabaseName();

        if ($env === 'testing' && $database === 'webform_test') {
            return;
        }

        fwrite(STDERR, sprintf(
            "\nRefusing to run tests: APP_ENV=%s, database=%s (expected testing / webform_test).\n".
            "Use `make test`, which sets the test environment explicitly.\n\n",
            $env, $database,
        ));

        exit(1);
    }
}
