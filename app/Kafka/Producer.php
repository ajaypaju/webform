<?php

namespace App\Kafka;

use RdKafka\Conf;
use RdKafka\Exception as RdKafkaException;
use RdKafka\Message;
use RdKafka\Producer as RdKafkaProducer;
use RdKafka\ProducerTopic;

final class Producer
{
    private RdKafkaProducer $producer;

    /** @var array<string, ProducerTopic> */
    private array $topics = [];

    private int $flushTimeoutMs;

    // Opaque token of the message send() is currently waiting on, and the error code its delivery report carried.
    private ?string $awaiting = null;

    private ?int $result = null;

    /**
     * @param  array<string, string>  $settings  librdkafka producer properties (config/kafka.php: producer)
     */
    public function __construct(string $brokers, array $settings)
    {
        $conf = new Conf;
        $conf->set('bootstrap.servers', $brokers);

        foreach ($settings as $name => $value) {
            $conf->set($name, $value);
        }

        $conf->setDrMsgCb(function (RdKafkaProducer $_, Message $message): void {
            if ($message->opaque === $this->awaiting) {
                $this->result = $message->err;
            }
        });

        // WHY: flush must outlive message.timeout.ms so an expiring message always produces a delivery
        // report (carrying an error) instead of flush merely timing out with the message still queued.
        $this->flushTimeoutMs = (int) $settings['message.timeout.ms'] + 1000;

        $this->producer = new RdKafkaProducer($conf);
    }

    /**
     * Produce one record and return only once the broker has acknowledged it.
     *
     * @throws DeliveryFailed if the broker did not ack within message.timeout.ms, acked with an error, or produce() itself failed
     */
    public function send(string $topic, string $key, string $payload): void
    {
        $this->awaiting = bin2hex(random_bytes(8));
        $this->result = null;

        try {
            // I14: partition is chosen by hashing the key, so one hot form spreads across partitions.
            $this->topic($topic)->produce(RD_KAFKA_PARTITION_UA, 0, $payload, $key, $this->awaiting);

            // I1: produce() only enqueued locally. Drain the queue (bounded), then trust nothing but this
            // message's own delivery report.
            $this->producer->flush($this->flushTimeoutMs);
        } catch (RdKafkaException $e) {
            throw DeliveryFailed::fromException($e);
        } finally {
            $err = $this->result;
            $this->awaiting = null;
            $this->result = null;
        }

        if ($err === null) {
            throw DeliveryFailed::noReport($this->flushTimeoutMs);
        }

        if ($err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw DeliveryFailed::withError($err);
        }
    }

    private function topic(string $name): ProducerTopic
    {
        return $this->topics[$name] ??= $this->producer->newTopic($name);
    }
}
