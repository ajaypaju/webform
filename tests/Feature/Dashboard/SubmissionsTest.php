<?php

use App\Submissions\CsvExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tenant = tenant();
    $this->session = ['tenant_id' => $this->tenant];
    ['form' => $this->form, 'version' => $this->v1] = form($this->tenant, 'published', ['fields' => [
        ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
        ['id' => 'seats', 'type' => 'number', 'label' => 'Seats', 'required' => false],
    ]]);
});

function viewer($test, string $path)
{
    return $test->withSession($test->session)->get($path);
}

/** Second version: `name` relabelled, `seats` dropped, `plan` added. */
function publishV2($test): string
{
    $v2 = (string) Str::uuid7();
    DB::connection('pgsql')->table('form_versions')->insert(['id' => $v2, 'form_id' => $test->form, 'tenant_id' => $test->tenant, 'version_no' => 2, 'published_at' => now(), 'definition' => json_encode(['fields' => [
        ['id' => 'name', 'type' => 'text', 'label' => 'Full name', 'required' => true],
        ['id' => 'plan', 'type' => 'select', 'label' => 'Plan', 'required' => true, 'options' => [['value' => 'free', 'label' => 'Free']]],
    ]])]);
    DB::connection('pgsql')->table('forms')->where('id', $test->form)->update(['current_version_id' => $v2]);

    return $v2;
}

/** Received timestamps in table order, and the prev/next links, from a rendered list page. */
function pageRows(string $html): array
{
    preg_match_all('#<a class="row-link" href="[^"]*/submissions/([0-9a-f-]+)">#', $html, $m);
    preg_match('#<a href="([^"]+)" rel="prev">#', $html, $prev);
    preg_match('#<a href="([^"]+)" rel="next">#', $html, $next);

    return ['ids' => $m[1], 'prev' => isset($prev[1]) ? html_entity_decode($prev[1]) : null, 'next' => isset($next[1]) ? html_entity_decode($next[1]) : null];
}

it('lists columns as the union across versions with the latest label, and tells absent fields from empty answers', function () {
    $v2 = publishV2($this);
    storeRow($this->tenant, $this->form, $this->v1, ['name' => 'Ada', 'seats' => null], '2026-05-01 10:00:00+00');
    storeRow($this->tenant, $this->form, $v2, ['name' => 'Bob', 'plan' => 'free'], '2026-05-02 10:00:00+00');

    $html = viewer($this, "/dashboard/forms/{$this->form}/submissions")->assertOk()->getContent();

    // I5: the header is CsvExport's own column list — the screen and the file cannot disagree.
    $labels = array_column(asTenant('pgsql_api', $this->tenant, fn () => CsvExport::columns($this->form)), 'label');
    expect($labels)->toBe(['Full name', 'Seats', 'Plan']);
    preg_match_all('#<th>([^<]*)</th>#', $html, $th);
    expect($th[1])->toBe(['Received (UTC)', 'Version', ...$labels]);

    preg_match_all('#<tr>\s*<td><a class="row-link"[^>]*>([^<]*)</a></td>\s*<td>([^<]*)</td>(.*?)</tr>#s', $html, $tr, PREG_SET_ORDER);
    expect($tr[0][1])->toBe('2026-05-02 10:00:00')->and($tr[0][2])->toBe('v2')
        ->and($tr[0][3])->toContain('<td class="value">Bob</td>', '<td class="absent" title="Not a field in this submission\'s version">n/a</td>', '<td class="value">free</td>');
    expect($tr[1][2])->toBe('v1')
        ->and($tr[1][3])->toContain('<td class="value">Ada</td>', '<td class="empty"></td>')
        ->and(substr_count($tr[1][3], 'class="absent"'))->toBe(1);
    expect($html)->toContain('showing 2 of many');
});

// I15: keyset both ways on (received_at, id); going forward then back lands on the same rows, ties included.
it('pages forward and back onto the same rows, including duplicate received_at', function () {
    foreach (range(1, 7) as $i) {
        storeRow($this->tenant, $this->form, $this->v1, ['name' => "r{$i}"], '2026-05-01 10:00:00.000001+00');
    }
    foreach (range(8, 11) as $i) {
        storeRow($this->tenant, $this->form, $this->v1, ['name' => "r{$i}"], sprintf('2026-05-01 10:00:0%d.500000+00', $i - 7));
    }

    $p1 = pageRows(viewer($this, "/dashboard/forms/{$this->form}/submissions?limit=3")->assertOk()->getContent());
    $p2 = pageRows(viewer($this, $p1['next'])->assertOk()->getContent());
    $p3 = pageRows(viewer($this, $p2['next'])->assertOk()->getContent());
    $p4 = pageRows(viewer($this, $p3['next'])->assertOk()->getContent());

    $forward = [...$p1['ids'], ...$p2['ids'], ...$p3['ids'], ...$p4['ids']];
    expect($p1['prev'])->toBeNull()->and($p4['next'])->toBeNull()
        ->and(count($forward))->toBe(11)->and(array_unique($forward))->toHaveCount(11)
        ->and(array_map('count', [$p1['ids'], $p2['ids'], $p3['ids'], $p4['ids']]))->toBe([3, 3, 3, 2]);

    $b3 = pageRows(viewer($this, $p4['prev'])->assertOk()->getContent());
    $b2 = pageRows(viewer($this, $b3['prev'])->assertOk()->getContent());
    $b1 = pageRows(viewer($this, $b2['prev'])->assertOk()->getContent());

    expect($b3['ids'])->toBe($p3['ids'])->and($b2['ids'])->toBe($p2['ids'])->and($b1['ids'])->toBe($p1['ids'])
        ->and($b1['prev'])->toBeNull()
        ->and(pageRows(viewer($this, $b2['next'])->assertOk()->getContent())['ids'])->toBe($p3['ids']);

    viewer($this, "/dashboard/forms/{$this->form}/submissions?cursor=garbage")->assertOk()->assertSee('this page link is not valid')->assertDontSee('row-link');
});

it('keeps filters across pages and on the export link, matching the API for the same filters', function () {
    $v2 = publishV2($this);
    foreach (range(1, 5) as $i) {
        storeRow($this->tenant, $this->form, $this->v1, ['name' => 'Ada', 'seats' => 3], "2026-05-0{$i} 00:00:00+00");
        storeRow($this->tenant, $this->form, $v2, ['name' => 'Ada', 'plan' => 'free'], "2026-05-0{$i} 12:00:00+00");
        storeRow($this->tenant, $this->form, $this->v1, ['name' => 'Bob', 'seats' => 3], "2026-05-0{$i} 06:00:00+00");
    }

    $query = "from=2026-05-02T00:00:00&to=2026-05-04T23:59:59&version_id={$this->v1}&field=name&value=Ada";
    $html = viewer($this, "/dashboard/forms/{$this->form}/submissions?limit=2&{$query}")->assertOk()->getContent();
    $p1 = pageRows($html);
    $p2 = pageRows(viewer($this, $p1['next'])->assertOk()->getContent());

    $api = $this->withToken(apiKey($this->tenant))->getJson("/v1/forms/{$this->form}/submissions?{$query}")->assertOk()->json('data');
    expect([...$p1['ids'], ...$p2['ids']])->toBe(array_column($api, 'id'))->toHaveCount(3)
        ->and($p1['next'])->toContain('field=name', 'value=Ada', "version_id={$this->v1}", 'from=2026-05-02', 'limit=2');

    // The filter form shows the active filters, and the export link carries every one of them.
    expect($html)->toContain('name="value" value="Ada"', "value=\"{$this->v1}\" selected", 'value="name" selected');
    preg_match('#<a class="button" href="([^"]+)" data-export>#', $html, $export);
    $exportUrl = html_entity_decode($export[1]);
    expect($exportUrl)->toStartWith("http://localhost:8000/dashboard/forms/{$this->form}/submissions/export.csv?")
        ->toContain('field=name', 'value=Ada', "version_id={$this->v1}", 'to=2026-05-04')->not->toContain('limit=');

    $csv = viewer($this, $exportUrl)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=utf-8')->streamedContent();
    expect(substr_count($csv, "\r\n"))->toBe(4)->and($csv)->toStartWith('submission_id,received_at,form_version_id,"Full name",Seats,Plan');
});

// I3/I5: a submission is shown with the labels of the version it was validated against, not the current one.
it('shows a submission from a retired version with that version\'s own labels', function () {
    $id = storeRow($this->tenant, $this->form, $this->v1, ['name' => 'Ada', 'seats' => 4], '2026-05-01 10:00:00.250000+00');
    publishV2($this);

    $html = viewer($this, "/dashboard/forms/{$this->form}/submissions/{$id}")->assertOk()->getContent();
    preg_match_all('#<dt>([^<]*)<span class="muted">([^<]*)</span></dt>\s*<dd[^>]*>([^<]*)</dd>#', $html, $m, PREG_SET_ORDER);
    expect(array_map(fn ($r) => [trim($r[1]), $r[2], $r[3]], $m))->toBe([['Name', 'name', 'Ada'], ['Seats', 'seats', '4']])
        ->and($html)->not->toContain('Full name')->not->toContain('Plan')
        ->toContain('<dd>v1 ', $id, '2026-05-01T10:00:00.250000+00:00');

    $unanswered = storeRow($this->tenant, $this->form, $this->v1, ['name' => 'Bob'], '2026-05-01 11:00:00+00');
    viewer($this, "/dashboard/forms/{$this->form}/submissions/{$unanswered}")->assertOk()->assertSee('no answer');
});

it('shows meta as ip hash, truncated user agent and referer host', function () {
    $id = (string) Str::uuid7();
    DB::connection('pgsql')->table('submissions')->insert([
        'id' => $id, 'tenant_id' => $this->tenant, 'form_id' => $this->form, 'form_version_id' => $this->v1, 'received_at' => now(),
        'data' => '{"name":"Ada"}', 'meta' => json_encode(['ip_hash' => str_repeat('a', 64), 'user_agent' => str_repeat('Mozilla ', 40), 'referer' => 'example.com']),
    ]);

    $html = viewer($this, "/dashboard/forms/{$this->form}/submissions/{$id}")->assertOk()->getContent();
    expect($html)->toContain(str_repeat('a', 64), 'example.com')->not->toContain(str_repeat('Mozilla ', 40))
        ->and(preg_match('#<dd>(Mozilla [^<]*…)</dd>#u', $html, $ua))->toBe(1)
        ->and(mb_strlen($ua[1]))->toBe(80);
});

// I9: submitted values are end-user content; they must be inert in the table and in the detail view.
it('renders hostile submitted values inert in the table and the detail view', function () {
    $id = storeRow($this->tenant, $this->form, $this->v1, ['name' => '<img src=x onerror=alert(1)>', 'seats' => 1], '2026-05-01 10:00:00+00');
    storeRow($this->tenant, $this->form, $this->v1, ['name' => '</script><script>alert(1)</script>'], '2026-05-01 11:00:00+00');

    foreach (["/dashboard/forms/{$this->form}/submissions", "/dashboard/forms/{$this->form}/submissions/{$id}", "/dashboard/forms/{$this->form}/submissions?field=name&value=%3Cimg%20src%3Dx%20onerror%3Dalert(1)%3E"] as $path) {
        $html = viewer($this, $path)->assertOk()
            ->assertHeader('Content-Security-Policy', str_replace('frame-ancestors *', "frame-ancestors 'none'", App\Http\Middleware\PublicHeaders::CSP))
            ->getContent();
        expect($html)->toContain('&lt;img src=x onerror=alert(1)&gt;')->not->toContain('<img src=x')->not->toContain('<script>alert');
        expect(preg_match_all('#<script[^>]*>#', $html, $scripts))->toBe(substr_count($html, 'type="module"'));
    }
});

it("never reaches another tenant's submissions from a session", function () {
    $b = tenant();
    ['form' => $formB, 'version' => $versionB] = form($b);
    $rowB = storeRow($b, $formB, $versionB, [], '2026-05-01 10:00:00+00');

    viewer($this, "/dashboard/forms/{$formB}/submissions")->assertNotFound();
    viewer($this, "/dashboard/forms/{$formB}/submissions/{$rowB}")->assertNotFound();
    viewer($this, "/dashboard/forms/{$formB}/submissions/export.csv")->assertNotFound();
    // Even a row whose id is known, requested under one of A's own forms, is nothing to A.
    viewer($this, "/dashboard/forms/{$this->form}/submissions/{$rowB}")->assertNotFound();
    $this->flushSession()->get("/dashboard/forms/{$this->form}/submissions")->assertRedirect('/login');
});

it('tells a customer without submissions where they come from', function () {
    viewer($this, "/dashboard/forms/{$this->form}/submissions")->assertOk()->assertSee("http://localhost:8080/f/{$this->form}");

    ['form' => $draft] = form($this->tenant, 'draft');
    viewer($this, "/dashboard/forms/{$draft}/submissions")->assertOk()->assertSee('Publish the form')->assertDontSee('/f/');
});
