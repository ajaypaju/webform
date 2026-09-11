<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnsureSubmissionPartitions extends Command
{
    protected $signature = 'submissions:ensure-partitions {--months=3 : Months to have ready, counting the current one}';

    protected $description = 'Create monthly submissions partitions for the current and upcoming months (idempotent)';

    public function handle(): int
    {
        $month = CarbonImmutable::now('UTC')->startOfMonth();

        for ($i = 0; $i < (int) $this->option('months'); $i++) {
            $from = $month->addMonths($i);
            $name = 'submissions_'.$from->format('Y_m');

            DB::statement(sprintf(
                'CREATE TABLE IF NOT EXISTS %s PARTITION OF submissions FOR VALUES FROM (%s) TO (%s)',
                $name,
                DB::getPdo()->quote($from->toDateString()),
                DB::getPdo()->quote($from->addMonth()->toDateString()),
            ));

            $this->line("{$name}: ready");
        }

        return self::SUCCESS;
    }
}
