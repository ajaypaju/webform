<?php

use App\Ingest\BrokerUnavailable;
use App\Ingest\SubmissionProducer;
use App\Kafka\DeliveryFailed;
use App\Kafka\Producer;
use App\Kafka\Sender;

/** A Sender whose behaviour the test controls. */
function scriptedSender(): Sender
{
    return new class implements Sender
    {
        public int $calls = 0;

        public ?int $failWith = null;

        public function send(string $topic, string $key, string $payload): void
        {
            $this->calls++;

            if ($this->failWith !== null) {
                throw DeliveryFailed::withError($this->failWith);
            }
        }
    };
}

// I1
it('opens the circuit after N consecutive failures, fails fast during the cooldown, probes once after it, and closes on success', function () {
    $sender = scriptedSender();
    $sender->failWith = RD_KAFKA_RESP_ERR__MSG_TIMED_OUT;
    $producer = new SubmissionProducer(fn () => $sender, maxFailures: 3, cooldownSeconds: 10, topic: 't');
    $envelope = ['submission_id' => 'id', 'v' => 1];

    for ($i = 0; $i < 3; $i++) {
        expect(fn () => $producer->produce($envelope))->toThrow(BrokerUnavailable::class, 'timed out');
    }

    expect($producer->isOpen())->toBeTrue()->and($sender->calls)->toBe(3);

    expect(fn () => $producer->produce($envelope))->toThrow(BrokerUnavailable::class, 'Circuit open');
    expect($sender->calls)->toBe(3);

    $this->travel(11)->seconds();
    $sender->failWith = null;
    $producer->produce($envelope);

    expect($sender->calls)->toBe(4)->and($producer->isOpen())->toBeFalse();
    $producer->produce($envelope);
    expect($sender->calls)->toBe(5);
});

it('re-opens after a failed probe', function () {
    $sender = scriptedSender();
    $sender->failWith = RD_KAFKA_RESP_ERR__MSG_TIMED_OUT;
    $producer = new SubmissionProducer(fn () => $sender, maxFailures: 1, cooldownSeconds: 10, topic: 't');

    expect(fn () => $producer->produce(['submission_id' => 'a']))->toThrow(BrokerUnavailable::class, 'timed out');
    $this->travel(11)->seconds();
    expect(fn () => $producer->produce(['submission_id' => 'a']))->toThrow(BrokerUnavailable::class, 'timed out');
    expect(fn () => $producer->produce(['submission_id' => 'a']))->toThrow(BrokerUnavailable::class, 'Circuit open');
    expect($sender->calls)->toBe(2);
});

it('recreates the producer after a fatal librdkafka error', function () {
    $senders = [scriptedSender(), scriptedSender()];
    $built = 0;
    $producer = new SubmissionProducer(function () use (&$built, $senders) {
        return $senders[$built++];
    }, maxFailures: 5, cooldownSeconds: 10, topic: 't');

    $senders[0]->failWith = RD_KAFKA_RESP_ERR__FATAL;
    expect(fn () => $producer->produce(['submission_id' => 'a']))->toThrow(BrokerUnavailable::class);
    expect($built)->toBe(2);

    $producer->produce(['submission_id' => 'b']);
    expect($senders[0]->calls)->toBe(1)->and($senders[1]->calls)->toBe(1);
});

it('wraps a real delivery failure from an unreachable broker within the message timeout', function () {
    $producer = new SubmissionProducer(
        fn () => new Producer('127.0.0.1:9', [...config('kafka.producer'), 'message.timeout.ms' => '1000']),
        maxFailures: 5, cooldownSeconds: 10, topic: config('kafka.topics.submissions'),
    );

    $start = hrtime(true);
    expect(fn () => $producer->produce(['submission_id' => 'x', 'data' => new stdClass]))->toThrow(BrokerUnavailable::class);
    expect((hrtime(true) - $start) / 1e6)->toBeLessThan(3000);
});
