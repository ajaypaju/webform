<?php

namespace Tests\Support;

use Illuminate\Support\Str;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\TopicPartition;

/** Reads back exactly what was produced to a topic after construction (every partition, from its high watermark). */
final class TopicTail
{
    private KafkaConsumer $consumer;

    public function __construct(private string $topic)
    {
        $conf = new Conf;
        $conf->set('bootstrap.servers', config('kafka.brokers'));
        $conf->set('group.id', 'test-tail-'.Str::random(8));
        $conf->set('enable.auto.commit', 'false');

        $this->consumer = new KafkaConsumer($conf);
        $partitions = [];

        foreach ($this->consumer->getMetadata(false, $this->consumer->newTopic($topic), 5000)->getTopics() as $meta) {
            foreach ($meta->getPartitions() as $partition) {
                $low = $high = 0;
                $this->consumer->queryWatermarkOffsets($topic, $partition->getId(), $low, $high, 5000);
                $partitions[] = new TopicPartition($topic, $partition->getId(), $high);
            }
        }

        $this->consumer->assign($partitions);
    }

    /**
     * @return list<array{key: string, payload: array<string, mixed>}> messages produced since construction
     */
    public function drain(int $expect = 1, int $waitMs = 3000): array
    {
        $deadline = hrtime(true) + $waitMs * 1_000_000;
        $messages = [];

        while (count($messages) < $expect && hrtime(true) < $deadline) {
            $message = $this->consumer->consume(200);

            if ($message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
                $messages[] = ['key' => $message->key, 'payload' => json_decode($message->payload, true)];
            }
        }

        return $messages;
    }
}
