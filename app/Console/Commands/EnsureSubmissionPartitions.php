<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EnsureSubmissionPartitions extends Command
{
    protected $signature = 'submissions:ensure-partitions {--months=3 : Months to have ready, counting the current one}';

    protected $description = 'Create monthly submissions partitions for the current and upcoming months (idempotent)';

    public function handle(): int
    {
        // The definer function does the CREATE, so this works as the owner (migrate) and as the consumer's writer role.
        foreach (DB::select('select ensure_submission_partitions(?) as name', [(int) $this->option('months')]) as $row) {
            $this->line("{$row->name}: ready");
        }

        return self::SUCCESS;
    }
}
