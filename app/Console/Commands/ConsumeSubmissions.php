<?php

namespace App\Console\Commands;

use App\Consumer\BatchWriter;
use App\Consumer\Consumer;
use App\Kafka\Producer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;

class ConsumeSubmissions extends Command
{
    protected $signature = 'submissions:consume {--once : Process one batch and exit}';

    protected $description = 'Move submissions from the topic into PostgreSQL in batches, exactly once (I2)';

    public function handle(Producer $producer): int
    {
        $conf = new Conf;
        $conf->set('bootstrap.servers', config('kafka.brokers'));

        foreach (config('kafka.consumer') as $name => $value) {
            $conf->set($name, $value);
        }

        $conf->setRebalanceCb(function (KafkaConsumer $kafka, int $err, ?array $partitions) {
            $names = array_map(fn ($p) => $p->getTopic().'/'.$p->getPartition(), $partitions ?? []);

            match ($err) {
                RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS => [Log::info('consumer: partitions assigned', ['partitions' => $names]), $kafka->assign($partitions)],
                RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS => [Log::info('consumer: partitions revoked', ['partitions' => $names]), $kafka->assign(null)],
                default => Log::error('consumer: rebalance error', ['error' => rd_kafka_err2str($err)]),
            };
        });

        $kafka = new KafkaConsumer($conf);
        $kafka->subscribe([config('kafka.topics.submissions')]);

        $consumer = new Consumer($kafka, new BatchWriter(DB::connection()), $producer, config('kafka.topics.submissions'), config('kafka.topics.submissions_dlq'),
            heartbeatFile: storage_path('framework/consumer.heartbeat'));

        // WHY: finish the batch in flight, commit, then leave — docker stop / a rolling deploy must not cut a batch.
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $consumer->stop());
        pcntl_signal(SIGINT, fn () => $consumer->stop());

        $maintenance = fn () => $this->call('submissions:ensure-partitions');

        if ($this->option('once')) {
            $maintenance();
            $consumer->runOnce();

            return self::SUCCESS;
        }

        $consumer->run($maintenance);
        Log::info('consumer: stopped');

        return self::SUCCESS;
    }
}
