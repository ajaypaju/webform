<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tenant = tenant();
    $this->key = apiKey($this->tenant);
    ['form' => $this->form, 'version' => $this->version] = form($this->tenant, 'published', ['fields' => [
        ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
        ['id' => 'seats', 'type' => 'number', 'label' => 'Seats', 'required' => false],
    ]]);
});

function listSubmissions(string $key, string $form, string $query = '')
{
    return test()->withToken($key)->getJson("/v1/forms/{$form}/submissions{$query}");
}

// I15: keyset on (received_at DESC, id DESC) — walking pages never repeats or skips, even inside one microsecond.
it('pages by keyset without repeating or skipping across boundaries, including same-microsecond rows', function () {
    $ids = [];
    foreach (range(1, 7) as $i) {
        $ids[] = storeRow($this->tenant, $this->form, $this->version, ['name' => "r{$i}"], '2026-05-01 10:00:00.000001+00');
    }
    foreach (range(8, 11) as $i) {
        $ids[] = storeRow($this->tenant, $this->form, $this->version, ['name' => "r{$i}"], sprintf('2026-05-01 10:00:0%d.500000+00', $i - 7));
    }

    $seen = [];
    $cursor = null;
    $pages = 0;

    do {
        $response = listSubmissions($this->key, $this->form, '?limit=3'.($cursor ? "&cursor={$cursor}" : ''))->assertOk();
        $page = $response->json('data');
        expect(count($page))->toBeLessThanOrEqual(3);
        foreach ($page as $row) {
            $seen[] = $row['id'];
        }
        $cursor = $response->json('next_cursor');
        $pages++;
    } while ($cursor !== null);

    expect($pages)->toBe(4)
        ->and(count($seen))->toBe(11)
        ->and(array_unique($seen))->toHaveCount(11)
        ->and(collect($seen)->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all());

    // Newest first, ties broken by id descending: the four later rows come first, then the seven tied ones by id.
    expect(array_slice($seen, 0, 4))->toBe(array_reverse(array_slice($ids, 7)))
        ->and(array_slice($seen, 4))->toBe(array_reverse(array_slice($ids, 0, 7)));

    listSubmissions($this->key, $this->form, '?cursor=garbage')->assertStatus(422)->assertExactJson(['errors' => [['code' => 'cursor', 'field' => 'cursor']]]);
});

it('filters by time range, version and exact field value, and never shows another tenant', function () {
    ['version' => $v2] = ['version' => (string) Str::uuid7()];
    DB::connection('pgsql')->table('form_versions')->insert(['id' => $v2, 'form_id' => $this->form, 'tenant_id' => $this->tenant, 'version_no' => 2, 'definition' => '{"fields":[]}', 'published_at' => now()]);

    $early = storeRow($this->tenant, $this->form, $this->version, ['name' => 'Ada', 'seats' => 3], '2026-05-01 00:00:00+00');
    $late = storeRow($this->tenant, $this->form, $v2, ['name' => 'Bob', 'seats' => 3], '2026-05-03 00:00:00+00');
    $other = storeRow($this->tenant, $this->form, $this->version, ['name' => 'Ada', 'seats' => 5], '2026-05-02 00:00:00+00');

    $foreign = tenant();
    ['form' => $foreignForm, 'version' => $foreignVersion] = form($foreign);
    storeRow($foreign, $foreignForm, $foreignVersion, ['name' => 'Ada'], '2026-05-02 12:00:00+00');

    $ids = fn ($query) => array_column(listSubmissions($this->key, $this->form, $query)->assertOk()->json('data'), 'id');

    expect($ids(''))->toBe([$late, $other, $early])
        ->and($ids('?from=2026-05-02T00:00:00Z'))->toBe([$late, $other])
        ->and($ids('?to=2026-05-02T00:00:00Z'))->toBe([$other, $early])
        ->and($ids("?version_id={$v2}"))->toBe([$late])
        ->and($ids('?field=name&value=Ada'))->toBe([$other, $early])
        ->and($ids('?field=seats&value=3'))->toBe([$late, $early])
        ->and($ids('?field=name&value=3'))->toBe([]);

    expect(listSubmissions($this->key, $this->form)->json('data.0'))->toMatchArray(['id' => $late, 'form_version_id' => $v2, 'data' => ['name' => 'Bob', 'seats' => 3]])
        ->and(listSubmissions($this->key, $this->form)->json('data.0.received_at'))->toBe('2026-05-03T00:00:00.000000+00:00');

    listSubmissions(apiKey($foreign), $this->form)->assertNotFound();
    listSubmissions($this->key, $this->form, '?field=Bad.Id&value=x')->assertStatus(422)->assertExactJson(['errors' => [['code' => 'field_id', 'field' => 'field']]]);
    listSubmissions($this->key, $this->form, '?from=yesterday-ish')->assertStatus(422)->assertJsonPath('errors.0.code', 'timestamp');
});
