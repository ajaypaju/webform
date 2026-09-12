<?php

namespace App\Consumer;

use App\Kafka\DeliveryFailed;
use App\Kafka\Sender;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PDOException;
use RdKafka\KafkaConsumer;
use RdKafka\Message;
use RdKafka\TopicPartition;

// I2: batches from the topic into PostgreSQL, offsets committed only after the database has. Everything here
// is safe to replay: a crash anywhere re-delivers the batch and submission_ids collapses it.
final class Consumer
{
    private const IDLE_POLL_MS = 1000;

    private const BACKOFF_BASE_MS = 500;

    private const BACKOFF_CAP_MS = 30_000;

    private const LAG_SAMPLE_SECONDS = 5;

    private bool $stopping = false;

    private Closure $sleep;

    private int $lagSampledAt = 0;

    private int $lastLag = -1;

    /**
     * @param  Closure(int): void|null  $sleep  ms to wait between retries; injectable for tests
     * @param  Closure(): void|null  $afterDbCommit  test hook between the two commits (throw to simulate a crash)
     */
    public function __construct(
        private KafkaConsumer $consumer,
        private BatchWriter $writer,
        private Sender $dlq,
        private string $topic,
        private string $dlqTopic,
        private int $batchSize = 500,
        private int $batchWaitMs = 200,
        ?Closure $sleep = null,
        private ?Closure $afterDbCommit = null,
        private ?string $heartbeatFile = null,
    ) {
        $this->sleep = $sleep ?? fn (int $ms) => usleep($ms * 1000);
    }

    /** Loop until stop(); $maintenance runs at start and every $maintenanceEvery seconds. */
    public function run(?Closure $maintenance = null, int $maintenanceEvery = 86400): void
    {
        $lastMaintenance = 0;

        while (! $this->stopping) {
            if ($maintenance !== null && time() - $lastMaintenance >= $maintenanceEvery) {
                $maintenance();
                $lastMaintenance = time();
            }

            $this->runOnce();

            // Liveness for the container healthcheck: the loop is turning, whether or not messages arrive.
            if ($this->heartbeatFile !== null) {
                touch($this->heartbeatFile);
            }
        }

        $this->consumer->close();
    }

    public function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * Process one batch: poll, parse, store in one transaction, dead-letter the malformed, commit offsets.
     *
     * @return array<string, int|float>|null batch stats, or null when nothing arrived
     */
    public function runOnce(): ?array
    {
        $messages = $this->poll();

        if ($messages === []) {
            return null;
        }

        $started = hrtime(true);
        $items = array_map(fn (Message $m) => [$m, Envelope::parse($m->payload)], $messages);
        $rows = array_values(array_filter($items, fn ($item) => is_array($item[1])));
        $rejects = array_values(array_filter($items, fn ($item) => is_string($item[1])));

        for ($attempt = 0; ; $attempt++) {
            try {
                $stats = $this->store($rows, $rejects);

                if ($this->afterDbCommit !== null) {
                    ($this->afterDbCommit)();
                }

                // I2: offsets last. Commit before the database and a crash in between loses rows for good;
                // commit after and a crash in between only replays rows that submission_ids will reject.
                $this->consumer->commit();
                break;
            } catch (PDOException|DeliveryFailed $e) {
                // WHY: never skip. A database outage ends with every message stored, so the same batch is retried
                // from memory until it commits; offsets stay where they were.
                $delay = min(self::BACKOFF_CAP_MS, self::BACKOFF_BASE_MS * 2 ** min($attempt, 10));
                Log::warning('consumer: batch not stored, retrying', ['attempt' => $attempt + 1, 'delay_ms' => $delay, 'error' => $e->getMessage()]);
                ($this->sleep)($delay);
            }
        }

        $stats += ['count' => count($messages), 'rejected' => count($rejects), 'lag' => $this->lag($messages), 'duration_ms' => round((hrtime(true) - $started) / 1e6, 1)];
        $stats['duplicates'] = $stats['unique'] - $stats['inserted'];
        Log::info('consumer: batch stored', $stats);

        return $stats;
    }

    /**
     * @param  list<array{0: Message, 1: array<string, string>}>  $rows
     * @param  list<array{0: Message, 1: string}>  $rejects
     * @return array{unique: int, inserted: int}
     */
    private function store(array $rows, array $rejects): array
    {
        try {
            $stats = $this->writer->write(array_column($rows, 1));
        } catch (QueryException $e) {
            if (! self::isIntegrityViolation($e)) {
                throw $e;
            }

            // WHY: a row the schema refuses (say, an unknown version) is a poison message, not an outage. It must not
            // block the partition: store the rest one by one and dead-letter the offenders.
            $stats = ['unique' => 0, 'inserted' => 0];

            foreach ($rows as [$message, $row]) {
                try {
                    $one = $this->writer->write([$row]);
                    $stats['unique'] += $one['unique'];
                    $stats['inserted'] += $one['inserted'];
                } catch (QueryException $e) {
                    if (! self::isIntegrityViolation($e)) {
                        throw $e;
                    }

                    $rejects[] = [$message, 'db_reject:'.$e->getCode()];
                }
            }
        }

        foreach ($rejects as [$message, $reason]) {
            $this->dlq->send($this->dlqTopic, (string) $message->key, (string) $message->payload, [
                'reason' => $reason, 'source_topic' => $message->topic_name, 'source_partition' => (string) $message->partition, 'source_offset' => (string) $message->offset,
            ]);
        }

        return $stats;
    }

    /** @return list<Message> up to batchSize messages, at most batchWaitMs after the first arrives */
    private function poll(): array
    {
        $messages = [];
        $deadline = null;

        while (count($messages) < $this->batchSize && ! $this->stopping) {
            $wait = $deadline === null ? self::IDLE_POLL_MS : max(0, intdiv($deadline - hrtime(true), 1_000_000));

            if ($deadline !== null && $wait === 0) {
                break;
            }

            $message = $this->consumer->consume($wait);

            switch ($message->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    $messages[] = $message;
                    $deadline ??= hrtime(true) + $this->batchWaitMs * 1_000_000;
                    break;
                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    if ($deadline === null) {
                        return $messages;
                    }
                    break;
                default:
                    Log::warning('consumer: poll error', ['error' => $message->errstr()]);

                    if ($deadline === null) {
                        ($this->sleep)(self::BACKOFF_BASE_MS);

                        return $messages;
                    }
            }
        }

        return $messages;
    }

    /** Messages still on the topic behind this consumer, over the partitions this batch touched; sampled, not per batch. */
    private function lag(array $messages): int
    {
        // WHY: each watermark query is a broker round trip per partition; per batch it cost more than the insert.
        if (time() - $this->lagSampledAt < self::LAG_SAMPLE_SECONDS) {
            return $this->lastLag;
        }

        $this->lagSampledAt = time();
        $partitions = array_unique(array_map(fn (Message $m) => $m->partition, $messages));
        $lag = 0;

        try {
            $positions = $this->consumer->getOffsetPositions(array_map(fn ($p) => new TopicPartition($this->topic, $p), $partitions));

            foreach ($positions as $position) {
                $low = $high = 0;
                $this->consumer->queryWatermarkOffsets($this->topic, $position->getPartition(), $low, $high, 2000);
                $lag += max(0, $high - $position->getOffset());
            }
        } catch (\Throwable $e) {
            Log::debug('consumer: lag unavailable', ['error' => $e->getMessage()]);

            return $this->lastLag = -1;
        }

        return $this->lastLag = $lag;
    }

    private static function isIntegrityViolation(QueryException $e): bool
    {
        return str_starts_with((string) $e->getCode(), '23');
    }
}
