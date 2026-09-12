<?php

namespace Tests\Support;

use App\Consumer\BatchWriter;
use App\Consumer\Consumer;
use App\Kafka\Producer;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\TopicPartition;

/** Test-side wiring of the consumer: a group positioned at the topic's end, so a test only sees what it produces. */
final class ConsumerGroup
{
    public static function fresh(): string
    {
        $group = 'test-consumer-'.Str::random(8);
        $kafka = self::kafkaConsumer($group);
        $offsets = [];

        foreach (self::partitions($kafka) as $partition) {
            $low = $high = 0;
            $kafka->queryWatermarkOffsets(config('kafka.topics.submissions'), $partition, $low, $high, 5000);
            $offsets[] = new TopicPartition(config('kafka.topics.submissions'), $partition, $high);
        }

        $kafka->assign($offsets);
        $kafka->commit($offsets);
        $kafka->close();

        return $group;
    }

    /** A Consumer over all partitions, starting at $group's committed offsets; sleep and the crash hook are injectable. */
    public static function consumer(string $group, ?Closure $sleep = null, ?Closure $afterDbCommit = null): Consumer
    {
        $kafka = self::kafkaConsumer($group);
        $topic = config('kafka.topics.submissions');
        $kafka->assign(array_map(fn ($p) => new TopicPartition($topic, $p, RD_KAFKA_OFFSET_STORED), self::partitions($kafka)));

        return new Consumer($kafka, new BatchWriter(DB::connection('pgsql_writer')), app(Producer::class), $topic, config('kafka.topics.submissions_dlq'),
            batchSize: 500, batchWaitMs: 300, sleep: $sleep ?? fn () => null, afterDbCommit: $afterDbCommit);
    }

    private static function kafkaConsumer(string $group): KafkaConsumer
    {
        $conf = new Conf;
        $conf->set('bootstrap.servers', config('kafka.brokers'));
        $conf->set('group.id', $group);
        $conf->set('enable.auto.commit', 'false');
        $conf->set('auto.offset.reset', 'earliest');

        return new KafkaConsumer($conf);
    }

    /** @return list<int> */
    private static function partitions(KafkaConsumer $kafka): array
    {
        $topic = config('kafka.topics.submissions');
        $ids = [];

        foreach ($kafka->getMetadata(false, $kafka->newTopic($topic), 5000)->getTopics() as $meta) {
            foreach ($meta->getPartitions() as $partition) {
                $ids[] = $partition->getId();
            }
        }

        return $ids;
    }
}
