<?php

return [

    'brokers' => env('KAFKA_BROKERS', 'redpanda:9092'),

    'topics' => [
        'submissions' => env('KAFKA_TOPIC_SUBMISSIONS', 'submissions'),
        'submissions_dlq' => env('KAFKA_TOPIC_SUBMISSIONS_DLQ', 'submissions.dlq'),
    ],

    // I1: acks=all + idempotence so an ack means the leader replicated and persisted the record;
    // message.timeout.ms bounds how long a produce can sit unacked before it fails.
    // librdkafka wants every value as a string.
    'producer' => [
        'acks' => 'all',
        'enable.idempotence' => 'true',
        'message.timeout.ms' => (string) env('KAFKA_MESSAGE_TIMEOUT_MS', 5000),
        'linger.ms' => (string) env('KAFKA_LINGER_MS', 5),
    ],

    // I2: offsets are committed by the consumer after its DB transaction, never automatically.
    'consumer' => [
        'group.id' => env('KAFKA_CONSUMER_GROUP', 'submissions-consumer'),
        'enable.auto.commit' => 'false',
        'auto.offset.reset' => 'earliest',
    ],

];
