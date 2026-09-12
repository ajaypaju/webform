<?php

use App\Consumer\BatchWriter;
use App\Consumer\Envelope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function envelope(string $tenant, string $form, string $version, array $overrides = []): string
{
    return json_encode(array_merge([
        'v' => 1, 'submission_id' => (string) Str::uuid7(), 'tenant_id' => $tenant, 'form_id' => $form, 'form_version_id' => $version,
        'received_at' => '2026-03-04T05:06:07.123456+00:00', 'data' => ['email' => 'a@b.co'], 'meta' => ['ip_hash' => 'x'],
    ], $overrides), JSON_UNESCAPED_SLASHES);
}

function writer(): BatchWriter
{
    return new BatchWriter(DB::connection('pgsql_writer'));
}

// I2
it('stores each unique id once: duplicates inside a batch and across batches both collapse', function () {
    $tenant = tenant();
    ['form' => $form, 'version' => $version] = form($tenant);
    $a = Envelope::parse(envelope($tenant, $form, $version));
    $b = Envelope::parse(envelope($tenant, $form, $version));

    expect(writer()->write([$a, $b, $a]))->toBe(['unique' => 2, 'inserted' => 2]);
    expect(writer()->write([$a, Envelope::parse(envelope($tenant, $form, $version))]))->toBe(['unique' => 2, 'inserted' => 1]);
    expect(writer()->write([]))->toBe(['unique' => 0, 'inserted' => 0]);

    expect(DB::connection('pgsql')->table('submissions')->count())->toBe(3)
        ->and(DB::connection('pgsql')->table('submission_ids')->count())->toBe(3);
});

it('stores received_at from the envelope, never now(), and the data and meta as given', function () {
    $tenant = tenant();
    ['form' => $form, 'version' => $version] = form($tenant);
    $row = Envelope::parse(envelope($tenant, $form, $version, ['data' => ['name' => 'Ada', 'tags' => ['x', 'y']], 'meta' => ['ip_hash' => 'h', 'referer' => null]]));

    writer()->write([$row]);

    $stored = DB::connection('pgsql')->table('submissions')->where('id', $row['id'])->first();
    expect($stored->received_at)->toStartWith('2026-03-04 05:06:07.123456')
        ->and(json_decode($stored->data, true))->toBe(['name' => 'Ada', 'tags' => ['x', 'y']])
        ->and(json_decode($stored->meta, true))->toBe(['ip_hash' => 'h', 'referer' => null]);
});

it('rejects malformed envelopes with a reason and never re-validates the data inside', function () {
    $ok = json_decode(envelope('t', 'f', 'v'), true);
    $ids = ['tenant_id' => (string) Str::uuid7(), 'form_id' => (string) Str::uuid7(), 'form_version_id' => (string) Str::uuid7()];
    $valid = json_encode([...$ok, ...$ids]);

    expect(Envelope::parse('not json'))->toBe('invalid_json')
        ->and(Envelope::parse(json_encode([...$ok, ...$ids, 'v' => 2])))->toBe('unsupported_version')
        ->and(Envelope::parse(json_encode(array_diff_key([...$ok, ...$ids], ['received_at' => 1]))))->toBe('missing_field:received_at')
        ->and(Envelope::parse(json_encode([...$ok, ...$ids, 'submission_id' => 'nope'])))->toBe('invalid_uuid:submission_id')
        ->and(Envelope::parse(json_encode([...$ok, ...$ids, 'received_at' => 'yesterday'])))->toBe('invalid_received_at')
        ->and(Envelope::parse(json_encode([...$ok, ...$ids, 'data' => 'x'])))->toBe('invalid_data')
        ->and(Envelope::parse(json_encode([...$ok, ...$ids, 'data' => ['anything' => ['goes' => 1]]])))->toBeArray();

    $row = Envelope::parse($valid);
    expect($row['received_at'])->toBe('2026-03-04 05:06:07.123456+00:00')
        ->and($row['data'])->toBe('{"email":"a@b.co"}');
});
