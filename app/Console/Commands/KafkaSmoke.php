<?php

namespace App\Console\Commands;

use App\Kafka\Producer;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\TopicPartition;

class KafkaSmoke extends Command
{
    protected $signature = 'kafka:smoke {--timeout=10 : Seconds to wait for the message to come back}';

    protected $description = 'Produce one message to the submissions topic, consume it back, print the round-trip time';

    public function handle(Producer $producer): int
    {
        $topic = config('kafka.topics.submissions');
        $key = (string) Str::uuid7();

        // WHY: start reading at each partition's current end so we only see what we're about to send,
        // no matter how much is already on the topic. A throwaway group id keeps this out of the real consumer's offsets.
        $consumer = new KafkaConsumer($this->consumerConf());
        $consumer->assign($this->tailOf($consumer, $topic));

        $start = hrtime(true);
        $producer->send($topic, $key, json_encode(['smoke' => true, 'sent_at' => now()->toIso8601String()]));
        $acked = hrtime(true);

        $deadline = $start + (int) $this->option('timeout') * 1_000_000_000;

        while (hrtime(true) < $deadline) {
            $message = $consumer->consume(500);

            if ($message->err === RD_KAFKA_RESP_ERR_NO_ERROR && $message->key === $key) {
                $seen = hrtime(true);
                $this->info(sprintf(
                    'acked in %.2f ms, consumed back in %.2f ms (partition %d, offset %d)',
                    ($acked - $start) / 1e6, ($seen - $start) / 1e6, $message->partition, $message->offset,
                ));

                return self::SUCCESS;
            }
        }

        $this->error("Message was acked but did not come back within {$this->option('timeout')}s.");

        return self::FAILURE;
    }

    private function consumerConf(): Conf
    {
        $conf = new Conf;
        $conf->set('bootstrap.servers', config('kafka.brokers'));
        $conf->set('group.id', 'kafka-smoke-'.Str::random(8));
        $conf->set('enable.auto.commit', 'false');

        return $conf;
    }

    /** @return list<TopicPartition> every partition of $topic, positioned at its high watermark */
    private function tailOf(KafkaConsumer $consumer, string $topic): array
    {
        $partitions = [];

        foreach ($consumer->getMetadata(false, $consumer->newTopic($topic), 5000)->getTopics() as $meta) {
            foreach ($meta->getPartitions() as $partition) {
                $low = $high = 0;
                $consumer->queryWatermarkOffsets($topic, $partition->getId(), $low, $high, 5000);
                $partitions[] = new TopicPartition($topic, $partition->getId(), $high);
            }
        }

        return $partitions;
    }
}
