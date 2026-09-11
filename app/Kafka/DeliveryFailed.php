<?php

namespace App\Kafka;

use RdKafka\Exception as RdKafkaException;
use RuntimeException;

final class DeliveryFailed extends RuntimeException
{
    public static function withError(int $code): self
    {
        return new self(sprintf('Kafka delivery failed: %s (%d)', rd_kafka_err2str($code), $code), $code);
    }

    public static function noReport(int $waitedMs): self
    {
        return new self("Kafka delivery unconfirmed: no delivery report within {$waitedMs} ms");
    }

    public static function fromException(RdKafkaException $e): self
    {
        return new self('Kafka produce failed: '.$e->getMessage(), $e->getCode(), $e);
    }
}
