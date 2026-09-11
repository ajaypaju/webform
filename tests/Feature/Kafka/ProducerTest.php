<?php

use App\Kafka\DeliveryFailed;
use App\Kafka\Producer;
use Illuminate\Support\Str;

// I1
it('returns only after the real broker acks the message', function () {
    $producer = app(Producer::class);

    expect(fn () => $producer->send(config('kafka.topics.submissions'), (string) Str::uuid7(), '{"test":true}'))
        ->not->toThrow(DeliveryFailed::class);
});

// I1
it('throws within the message timeout when the broker is unreachable', function () {
    // WHY: port 9 (discard) refuses immediately, so the failure comes from librdkafka giving up, not a TCP hang.
    $producer = new Producer('127.0.0.1:9', [...config('kafka.producer'), 'message.timeout.ms' => '1000']);

    $start = hrtime(true);

    expect(fn () => $producer->send(config('kafka.topics.submissions'), 'k', 'p'))
        ->toThrow(DeliveryFailed::class);

    expect((hrtime(true) - $start) / 1e6)->toBeLessThan(3000);
});
