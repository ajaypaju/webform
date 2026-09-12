<?php

namespace App\Ingest;

use App\Kafka\DeliveryFailed;
use App\Kafka\Sender;
use Closure;
use Illuminate\Support\Facades\Log;

// I1: produce + flush + delivery report live in the Sender; this adds what a burst during an outage needs.
// Per worker, in memory: Octane keeps this singleton alive across requests.
final class SubmissionProducer
{
    private Sender $producer;

    private int $consecutiveFailures = 0;

    private ?int $openedAt = null;

    /**
     * @param  Closure(): Sender  $factory  builds a fresh producer; called at start and after a fatal error
     */
    public function __construct(private Closure $factory, private int $maxFailures, private int $cooldownSeconds, private string $topic)
    {
        $this->producer = ($this->factory)();
    }

    /**
     * @param  array<string, mixed>  $envelope  keyed by submission_id on the topic (I14)
     *
     * @throws BrokerUnavailable when the breaker is open or the broker did not confirm delivery
     */
    public function produce(array $envelope): void
    {
        // WHY: with the broker down every request would otherwise hold a worker for message.timeout.ms; once
        // open, requests fail in microseconds until one probe per cooldown shows the broker is back.
        if ($this->openedAt !== null && now()->timestamp - $this->openedAt < $this->cooldownSeconds) {
            throw new BrokerUnavailable('Circuit open: broker recently unavailable.');
        }

        try {
            $this->producer->send($this->topic, $envelope['submission_id'], json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } catch (DeliveryFailed $e) {
            $this->failed($e);

            throw new BrokerUnavailable($e->getMessage(), 0, $e);
        }

        $this->consecutiveFailures = 0;
        $this->openedAt = null;
    }

    public function isOpen(): bool
    {
        return $this->openedAt !== null;
    }

    private function failed(DeliveryFailed $e): void
    {
        $this->consecutiveFailures++;

        // WHY: an idempotent producer that hit a fatal error refuses every further produce; only a new client recovers.
        if ($e->getCode() === RD_KAFKA_RESP_ERR__FATAL) {
            Log::error('submission producer: fatal librdkafka error, recreating the producer', ['error' => $e->getMessage()]);
            $this->producer = ($this->factory)();
        }

        if ($this->consecutiveFailures >= $this->maxFailures) {
            $this->openedAt = now()->timestamp;
        }
    }
}
