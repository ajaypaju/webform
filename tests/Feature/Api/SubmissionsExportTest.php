<?php

use App\Submissions\CsvExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function addVersion(string $tenant, string $form, int $no, array $fields): string
{
    $id = (string) Str::uuid7();
    DB::connection('pgsql')->table('form_versions')->insert([
        'id' => $id, 'form_id' => $form, 'tenant_id' => $tenant, 'version_no' => $no, 'definition' => json_encode(['fields' => $fields]), 'published_at' => now(),
    ]);
    DB::connection('pgsql')->table('forms')->where('id', $form)->update(['current_version_id' => $id]);

    return $id;
}

function csvRows(string $body): array
{
    $rows = array_map(fn ($line) => str_getcsv($line, ',', '"', ''), preg_split('/\r\n/', rtrim($body, "\r\n")));

    return $rows;
}

beforeEach(function () {
    $this->tenant = tenant();
    $this->key = apiKey($this->tenant);
});

// I5 payoff: the union of ids across versions, headed by the latest label; old rows keep their own columns.
it('exports across versions where v2 renames a field, deletes one and adds one', function () {
    ['form' => $form, 'version' => $v1] = form($this->tenant, 'published', ['fields' => [
        ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
        ['id' => 'phone', 'type' => 'text', 'label' => 'Phone', 'required' => false],
        ['id' => 'tags', 'type' => 'multiselect', 'label' => 'Tags', 'required' => false, 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]],
    ]]);
    $v2 = addVersion($this->tenant, $form, 2, [
        ['id' => 'name', 'type' => 'text', 'label' => 'Full name', 'required' => true],
        ['id' => 'tags', 'type' => 'multiselect', 'label' => 'Tags', 'required' => false, 'options' => [['value' => 'a', 'label' => 'A']]],
        ['id' => 'company', 'type' => 'text', 'label' => 'Company', 'required' => false],
    ]);
    $old = storeRow($this->tenant, $form, $v1, ['name' => 'Ada', 'phone' => '555', 'tags' => ['a', 'b']], '2026-05-01 00:00:00+00');
    $new = storeRow($this->tenant, $form, $v2, ['name' => 'Bob', 'company' => 'Acme', 'tags' => []], '2026-05-02 00:00:00+00');

    $response = $this->withToken($this->key)->get("/v1/forms/{$form}/submissions/export.csv")->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Content-Disposition'))->toContain("submissions-{$form}.csv");

    $rows = csvRows($response->streamedContent());

    expect($rows[0])->toBe(['submission_id', 'received_at', 'form_version_id', 'Full name', 'Phone', 'Tags', 'Company'])
        ->and($rows[1])->toBe([$new, '2026-05-02T00:00:00.000000+00:00', $v2, 'Bob', '', '', 'Acme'])
        ->and($rows[2])->toBe([$old, '2026-05-01T00:00:00.000000+00:00', $v1, 'Ada', '555', 'a; b', ''])
        ->and($rows)->toHaveCount(3);

    expect(csvRows($this->withToken($this->key)->get("/v1/forms/{$form}/submissions/export.csv?version_id={$v1}")->streamedContent()))->toHaveCount(2);
    $this->withToken(apiKey(tenant()))->get("/v1/forms/{$form}/submissions/export.csv")->assertNotFound();
});

// I10 + RFC 4180
it('neutralises spreadsheet formulas and quote-escapes quotes and newlines', function () {
    ['form' => $form, 'version' => $version] = form($this->tenant, 'published', ['fields' => [
        ['id' => 'note', 'type' => 'text', 'label' => 'Note', 'required' => false],
    ]]);
    $formula = storeRow($this->tenant, $form, $version, ['note' => '=HYPERLINK("http://evil","x")'], '2026-05-01 00:00:03+00');
    $quoted = storeRow($this->tenant, $form, $version, ['note' => "He said \"hi\",\nthen left"], '2026-05-01 00:00:02+00');
    $minus = storeRow($this->tenant, $form, $version, ['note' => '-5 degrees'], '2026-05-01 00:00:01+00');
    $tab = storeRow($this->tenant, $form, $version, ['note' => "\tindented"], '2026-05-01 00:00:00+00');

    $body = $this->withToken($this->key)->get("/v1/forms/{$form}/submissions/export.csv")->assertOk()->streamedContent();
    $rows = csvRows($body);

    expect($rows[1][3])->toBe("'=HYPERLINK(\"http://evil\",\"x\")")
        ->and($rows[2][3])->toBe("He said \"hi\",\nthen left")
        ->and($rows[3][3])->toBe("'-5 degrees")
        ->and($rows[4][3])->toBe("'\tindented")
        ->and($body)->toContain("\"'=HYPERLINK(\"\"http://evil\"\",\"\"x\"\")\"")
        ->and($body)->toContain("\"He said \"\"hi\"\",\nthen left\"")
        ->and($body)->not->toContain(",=HYPERLINK");

    expect(CsvExport::guard('@sum'))->toBe("'@sum")->and(CsvExport::guard('+1'))->toBe("'+1")->and(CsvExport::guard("\rx"))->toBe("'\rx")->and(CsvExport::guard('plain'))->toBe('plain');
});

// I15
it('streams ~20k rows in keyset chunks of 1000 with bounded memory', function () {
    ['form' => $form, 'version' => $version] = form($this->tenant, 'published', ['fields' => [
        ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
        ['id' => 'note', 'type' => 'text', 'label' => 'Note', 'required' => false],
    ]]);
    $note = str_repeat('x', 400);
    foreach (array_chunk(range(1, 20_000), 1000) as $chunk) {
        DB::connection('pgsql')->table('submissions')->insert(array_map(fn ($i) => [
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant, 'form_id' => $form, 'form_version_id' => $version,
            'data' => json_encode(['name' => "r{$i}", 'note' => $note]), 'meta' => '{}', 'received_at' => now()->subSeconds(20_000 - $i),
        ], $chunk));
    }

    $selects = 0;
    DB::listen(function ($query) use (&$selects) {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "submissions"')) {
            $selects++;
        }
    });

    gc_collect_cycles();
    $before = memory_get_peak_usage(true);
    $response = $this->withToken($this->key)->get("/v1/forms/{$form}/submissions/export.csv")->assertOk();
    expect($response->baseResponse)->toBeInstanceOf(Symfony\Component\HttpFoundation\StreamedResponse::class);

    $lines = substr_count($response->streamedContent(), "\r\n");
    $peakDelta = (memory_get_peak_usage(true) - $before) / 1024 / 1024;

    expect($lines)->toBe(20_001)
        ->and($selects)->toBe(20)
        ->and($peakDelta)->toBeLessThan(64);

    fwrite(STDERR, sprintf("\nexport of %d rows: %d chunked selects, peak memory delta %.1f MB (includes the test client buffering the %.1f MB body)\n", $lines - 1, $selects, $peakDelta, strlen($response->streamedContent()) / 1048576));
});
