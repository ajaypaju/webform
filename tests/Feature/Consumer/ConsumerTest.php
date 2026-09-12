<?php

use App\Kafka\Producer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\ConsumerGroup;
use Tests\Support\TopicTail;

function produceRaw(array $payloads): void
{
    foreach ($payloads as $key => $payload) {
        app(Producer::class)->send(config('kafka.topics.submissions'), is_string($key) ? $key : Illuminate\Support\Str::uuid7(), $payload);
    }
}

function rows(): int
{
    return DB::connection('pgsql')->table('submissions')->count();
}

beforeEach(function () {
    Log::spy();
    $this->tenant = tenant();
    ['form' => $this->form, 'version' => $this->version] = form($this->tenant);
});

// I2
it('stores three messages as three rows and commits the offsets', function () {
    $group = ConsumerGroup::fresh();
    produceRaw([envelope($this->tenant, $this->form, $this->version), envelope($this->tenant, $this->form, $this->version), envelope($this->tenant, $this->form, $this->version)]);

    $stats = ConsumerGroup::consumer($group)->runOnce();

    expect($stats)->toMatchArray(['count' => 3, 'unique' => 3, 'inserted' => 3, 'duplicates' => 0, 'rejected' => 0])
        ->and($stats['lag'])->toBe(0)
        ->and(rows())->toBe(3);

    // A fresh consumer of the same group sees nothing: the offsets were committed.
    expect(ConsumerGroup::consumer($group)->runOnce())->toBeNull();
    Log::shouldHaveReceived('info')->withArgs(fn ($msg, $ctx) => $msg === 'consumer: batch stored' && $ctx['inserted'] === 3)->once();
});

// I2
it('collapses the same submission id within one batch and across batches', function () {
    $group = ConsumerGroup::fresh();
    $twice = envelope($this->tenant, $this->form, $this->version);
    produceRaw([$twice, envelope($this->tenant, $this->form, $this->version), $twice]);

    expect(ConsumerGroup::consumer($group)->runOnce())->toMatchArray(['count' => 3, 'unique' => 2, 'inserted' => 2, 'duplicates' => 0]);

    produceRaw([$twice]);
    expect(ConsumerGroup::consumer($group)->runOnce())->toMatchArray(['count' => 1, 'unique' => 1, 'inserted' => 0, 'duplicates' => 1])
        ->and(rows())->toBe(2);
});

// I2: the exactly-once proof. Rows committed, offsets not, process dies; the replay must not add a row.
it('keeps exactly one row when the process dies between the database commit and the offset commit', function () {
    $group = ConsumerGroup::fresh();
    produceRaw([envelope($this->tenant, $this->form, $this->version), envelope($this->tenant, $this->form, $this->version)]);

    $crashing = ConsumerGroup::consumer($group, afterDbCommit: fn () => throw new RuntimeException('SIGKILL'));
    expect(fn () => $crashing->runOnce())->toThrow(RuntimeException::class, 'SIGKILL');
    expect(rows())->toBe(2);

    $restarted = ConsumerGroup::consumer($group);
    expect($restarted->runOnce())->toMatchArray(['count' => 2, 'unique' => 2, 'inserted' => 0, 'duplicates' => 2])
        ->and(rows())->toBe(2)
        ->and(ConsumerGroup::consumer($group)->runOnce())->toBeNull();
});

it('dead-letters malformed envelopes with a reason header and still stores the valid ones from the same batch', function () {
    $group = ConsumerGroup::fresh();
    $dlq = new TopicTail(config('kafka.topics.submissions_dlq'));
    produceRaw(['k-garbage' => 'not json', 'k-v2' => json_encode(['v' => 2]), envelope($this->tenant, $this->form, $this->version)]);

    expect(ConsumerGroup::consumer($group)->runOnce())->toMatchArray(['count' => 3, 'inserted' => 1, 'rejected' => 2])
        ->and(rows())->toBe(1)
        ->and(ConsumerGroup::consumer($group)->runOnce())->toBeNull();

    $dead = collect($dlq->drain(expect: 2))->keyBy('key');
    expect($dead)->toHaveCount(2)
        ->and($dead['k-garbage']['raw'])->toBe('not json')
        ->and($dead['k-garbage']['headers']['reason'])->toBe('invalid_json')
        ->and($dead['k-v2']['headers']['reason'])->toBe('unsupported_version')
        ->and($dead['k-v2']['headers']['source_topic'])->toBe(config('kafka.topics.submissions'));
});

it('dead-letters a row the schema rejects and keeps the rest of the batch', function () {
    $group = ConsumerGroup::fresh();
    $dlq = new TopicTail(config('kafka.topics.submissions_dlq'));
    produceRaw(['k-orphan' => envelope($this->tenant, $this->form, (string) Illuminate\Support\Str::uuid7()), envelope($this->tenant, $this->form, $this->version)]);

    expect(ConsumerGroup::consumer($group)->runOnce())->toMatchArray(['count' => 2, 'inserted' => 1])
        ->and(rows())->toBe(1)
        ->and($dlq->drain()[0]['headers']['reason'])->toBe('db_reject:23503');
});

// I12: a PostgreSQL outage delays, never skips.
it('retries the same batch with backoff while PostgreSQL is down and stores every message once it is back', function () {
    $group = ConsumerGroup::fresh();
    produceRaw([envelope($this->tenant, $this->form, $this->version), envelope($this->tenant, $this->form, $this->version)]);
    $good = config('database.connections.pgsql_writer');
    $delays = [];

    postgresDown('pgsql_writer');
    $consumer = ConsumerGroup::consumer($group, sleep: function (int $ms) use (&$delays, $good) {
        $delays[] = $ms;

        if (count($delays) === 2) {
            config(['database.connections.pgsql_writer' => $good]);
            DB::purge('pgsql_writer');
        }
    });

    expect($consumer->runOnce())->toMatchArray(['count' => 2, 'inserted' => 2])
        ->and($delays)->toBe([500, 1000])
        ->and(rows())->toBe(2)
        ->and(ConsumerGroup::consumer($group)->runOnce())->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => $msg === 'consumer: batch not stored, retrying')->twice();
});
