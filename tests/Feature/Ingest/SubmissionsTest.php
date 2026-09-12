<?php

use App\Ingest\RenderToken;
use App\Ingest\SubmissionProducer;
use App\Kafka\DeliveryFailed;
use App\Kafka\Sender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TopicTail;

// I1, I7, I14
it('accepts a valid submission with 202 and puts exactly the envelope on the topic, keyed by submission id', function () {
    ['form' => $form, 'version' => $version] = liveForm();
    $tail = new TopicTail(config('kafka.topics.submissions'));
    $body = payload($form, $version, ['email' => '  ada@example.com ', 'plan' => 'pro', 'seats' => 3]);

    $response = submit($form, $body)->assertStatus(202);
    $receivedAt = $response->json('received_at');

    expect($response->json('submission_id'))->toBe($body['submission_id'])
        ->and($receivedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/');

    $messages = $tail->drain();
    expect($messages)->toHaveCount(1)
        ->and($messages[0]['key'])->toBe($body['submission_id'])
        ->and($messages[0]['payload'])->toBe([
            'v' => 1,
            'submission_id' => $body['submission_id'],
            'tenant_id' => DB::connection('pgsql')->table('forms')->where('id', $form)->value('tenant_id'),
            'form_id' => $form,
            'form_version_id' => $version,
            'received_at' => $receivedAt,
            'data' => ['email' => 'ada@example.com', 'plan' => 'pro', 'seats' => 3],
            'meta' => ['ip_hash' => hash_hmac('sha256', IP, config('ingest.ip_hash_key')), 'user_agent' => 'PestBrowser/1.0', 'referer' => 'customer.example'],
        ]);

    expect(json_encode($messages[0]['payload']))->not->toContain(IP);
});

// I6, I7
it('returns 422 with codes for each class of invalid data, and drops hidden-field values from what is stored', function () {
    ['form' => $form, 'version' => $version] = liveForm();
    $tail = new TopicTail(config('kafka.topics.submissions'));

    foreach ([
        [[], ['email' => ['required']]],
        [['email' => 5], ['email' => ['type']]],
        [['email' => 'nope'], ['email' => ['email']]],
        [['email' => 'a@b.co', 'plan' => 'gold'], ['plan' => ['option']]],
        [['email' => 'a@b.co', 'plan' => 'pro', 'seats' => 1.5], ['seats' => ['integer']]],
        [['email' => 'a@b.co', 'code' => 'abc'], ['code' => ['pattern']]],
        [['email' => 'a@b.co', 'nope' => 1], ['nope' => ['unknown_field']]],
    ] as [$data, $errors]) {
        submit($form, payload($form, $version, $data))->assertStatus(422)->assertExactJson(['errors' => $errors]);
    }

    submit($form, payload($form, $version, ['email' => 'a@b.co', 'plan' => 'free', 'seats' => 'not even a number']))->assertStatus(202);

    expect($tail->drain()[0]['payload']['data'])->toBe(['email' => 'a@b.co', 'plan' => 'free']);
});

it('rejects a malformed envelope with codes and caps the body and the data keys', function () {
    ['form' => $form, 'version' => $version] = liveForm();

    submit($form, ['data' => 'x'])->assertStatus(422)->assertJsonPath('errors.0.code', 'required');
    submit($form, [...payload($form, $version, []), 'submission_id' => (string) Str::uuid()])->assertStatus(422)->assertExactJson(['errors' => [['code' => 'regex', 'field' => 'submission_id']]]);
    submit($form, payload($form, $version, array_fill_keys(array_map(fn ($i) => "k{$i}", range(1, 201)), 'x')))->assertStatus(422)->assertExactJson(['errors' => [['code' => 'too_many_fields', 'field' => 'data']]]);
    submit($form, payload($form, $version, ['email' => str_repeat('x', 270_000)]))->assertStatus(413)->assertExactJson(['errors' => [['code' => 'body_too_large']]]);
});

// I1: never 202 without a delivery report; the breaker turns a flush timeout per request into a fast 503.
it('returns 503 while the broker is down, opens the circuit, and recovers after the cooldown', function () {
    ['form' => $form, 'version' => $version] = liveForm();
    $sender = new class implements Sender
    {
        public int $calls = 0;

        public bool $down = true;

        public function send(string $topic, string $key, string $payload, array $headers = []): void
        {
            $this->calls++;

            if ($this->down) {
                throw DeliveryFailed::withError(RD_KAFKA_RESP_ERR__MSG_TIMED_OUT);
            }
        }
    };
    $this->app->instance(SubmissionProducer::class, new SubmissionProducer(fn () => $sender, 2, 10, 'submissions'));

    submit($form, payload($form, $version, ['email' => 'a@b.co']))->assertStatus(503)->assertHeader('Retry-After', '5');
    submit($form, payload($form, $version, ['email' => 'a@b.co']))->assertStatus(503);
    submit($form, payload($form, $version, ['email' => 'a@b.co']))->assertStatus(503);
    expect($sender->calls)->toBe(2);

    $this->travel(11)->seconds();
    $sender->down = false;
    submit($form, payload($form, $version, ['email' => 'a@b.co']))->assertStatus(202);
    expect($sender->calls)->toBe(3);
});

// I3
it('accepts a version superseded 23h ago and rejects one superseded 25h ago with 409 and the current id', function () {
    foreach ([23 => 202, 25 => 409] as $hoursAgo => $status) {
        $tenant = tenant();
        ['form' => $form, 'version' => $v1] = form($tenant, 'published', ['fields' => fields()]);
        $v2 = (string) Str::uuid7();
        DB::connection('pgsql')->table('form_versions')->insert([
            'id' => $v2, 'form_id' => $form, 'tenant_id' => $tenant, 'version_no' => 2,
            'definition' => json_encode(['fields' => fields()]), 'published_at' => now()->subHours($hoursAgo),
        ]);
        DB::connection('pgsql')->table('forms')->where('id', $form)->update(['current_version_id' => $v2]);

        $response = submit($form, payload($form, $v1, ['email' => 'a@b.co']))->assertStatus($status);

        if ($status === 409) {
            $response->assertExactJson(['code' => 'version_retired', 'current_version_id' => $v2]);
        }
    }
});

it("returns 404 for a version that belongs to another form, even with a token forged for it", function () {
    ['form' => $formA, 'version' => $versionA] = liveForm();
    ['form' => $formB] = liveForm();

    submit($formB, payload($formB, $versionA, ['email' => 'a@b.co']))->assertNotFound();
});

// I13
it('answers spam with a decoy 202 that carries a fresh id, and produces nothing', function () {
    ['form' => $form, 'version' => $version] = liveForm();
    ['version' => $otherVersion] = liveForm();
    $tail = new TopicTail(config('kafka.topics.submissions'));

    $cases = [
        'honeypot' => payload($form, $version, ['email' => 'a@b.co'], 5, ['honeypot' => 'http://spam']),
        'too fast' => payload($form, $version, ['email' => 'a@b.co'], 1),
        'expired' => payload($form, $version, ['email' => 'a@b.co'], 90_000),
        'wrong version' => [...payload($form, $version, ['email' => 'a@b.co']), 'render_token' => app(RenderToken::class)->issue($form, $otherVersion, now()->timestamp - 5)],
        'garbage token' => [...payload($form, $version, ['email' => 'a@b.co']), 'render_token' => 'nope'],
    ];

    foreach ($cases as $name => $body) {
        $response = submit($form, $body)->assertStatus(202);
        expect($response->json('submission_id'))->not->toBe($body['submission_id'], $name);
    }

    expect($tail->drain(expect: 1, waitMs: 700))->toBe([]);
});

// I13, I12
it('rate limits per ip and form with 429 + Retry-After, and keeps accepting when Redis is down', function () {
    config(['ingest.rate_limits.ip_form' => ['capacity' => 2, 'per_second' => 0.01]]);
    ['form' => $form, 'version' => $version] = liveForm();

    submit($form, payload($form, $version, ['email' => 'a@b.co']))->assertStatus(202);
    submit($form, payload($form, $version, ['email' => 'a@b.co']))->assertStatus(202);
    $limited = submit($form, payload($form, $version, ['email' => 'a@b.co']))->assertStatus(429)->assertExactJson(['error' => 'rate_limited']);
    expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThanOrEqual(1);

    ['form' => $fresh, 'version' => $freshVersion] = liveForm();
    $this->getJson("/v1/forms/{$fresh}")->assertOk();
    redisDown();
    submit($fresh, payload($fresh, $freshVersion, ['email' => 'a@b.co']))->assertStatus(202);
});
