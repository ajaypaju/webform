<?php

use App\Http\Middleware\PublicHeaders;
use App\Ingest\RenderToken;
use App\Ingest\VersionStore;

function publishedForm(array $fields): array
{
    return form(tenant(), 'published', ['fields' => $fields]);
}

it('renders the current published version with a definition block, a render token, and initial visibility', function () {
    ['form' => $form, 'version' => $version] = publishedForm([
        ['id' => 'plan', 'type' => 'select', 'label' => 'Plan', 'required' => true, 'options' => [['value' => 'pro', 'label' => 'Pro']]],
        ['id' => 'addons', 'type' => 'multiselect', 'label' => 'Add-ons', 'required' => false, 'options' => [['value' => 'a', 'label' => 'A']],
            'visible_if' => ['field' => 'plan', 'op' => 'eq', 'value' => 'pro']],
        ['id' => 'consent', 'type' => 'checkbox', 'label' => 'I agree', 'required' => true, 'help_text' => 'Please'],
    ]);

    $response = $this->get("/f/{$form}")->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    // Blade leaves whitespace around conditional attributes; compare the markup, not the spacing.
    $html = preg_replace(['/\s+/', '/\s+>/'], [' ', '>'], $response->getContent());

    expect($html)->toContain('<script type="application/json" id="form-definition">')
        ->toContain('<select id="f-plan" name="plan" required>')
        ->toContain('<fieldset data-field="addons" hidden>')
        ->toContain('<input type="checkbox" name="consent" value="1"> I agree')
        ->toContain('<p class="help">Please</p>')
        ->toContain('<noscript>')
        ->toMatch('~<script type="module" src="http://localhost:8000/build/assets/main-[^"]+\.js"></script>~')
        ->not->toContain('<script>');

    preg_match('/data-render-token="([^"]+)"/', $html, $m);
    expect(app(RenderToken::class)->verify($m[1], $form, $version))->toBeInt();
});

it('returns 404 for a draft-only form, an archived form, an unknown form, and a malformed id', function () {
    ['form' => $draft] = form(tenant(), 'draft');
    ['form' => $archived] = form(tenant(), 'archived');

    $this->get("/f/{$draft}")->assertNotFound();
    $this->get("/f/{$archived}")->assertNotFound();
    $this->get('/f/'.Illuminate\Support\Str::uuid7())->assertNotFound();
    $this->get('/f/not-a-uuid')->assertNotFound();
});

// I9
it('renders hostile labels and help text inert: escaped in markup, hex-escaped in the JSON block', function () {
    ['form' => $form] = publishedForm([
        ['id' => 'x', 'type' => 'text', 'label' => '<img src=x onerror=alert(1)>', 'required' => false, 'help_text' => '</script><script>alert(1)</script>'],
    ]);

    $html = $this->get("/f/{$form}")->assertOk()->getContent();

    expect($html)->toContain('&lt;img src=x onerror=alert(1)&gt;')
        ->toContain('&lt;/script&gt;&lt;script&gt;alert(1)&lt;/script&gt;')
        ->toContain('\u003C\/script\u003E\u003Cscript\u003Ealert(1)\u003C\/script\u003E')
        ->not->toContain('<img src=x')
        ->not->toContain('</script><script>');
});

// I9
it('sends the exact CSP, nosniff and referrer headers on every public response', function () {
    ['form' => $form, 'version' => $version] = publishedForm([]);

    foreach (["/f/{$form}", "/v1/forms/{$form}", "/v1/forms/{$form}/versions/{$version}", '/f/not-a-uuid'] as $path) {
        $this->get($path)
            ->assertHeader('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; worker-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors *")
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeaderMissing('X-Frame-Options');
    }

    expect(PublicHeaders::CSP)->not->toContain("'unsafe-inline'");
});

// I12
it('keeps rendering the page from Redis after PostgreSQL becomes unreachable', function () {
    ['form' => $form] = publishedForm([['id' => 'n', 'type' => 'text', 'label' => 'Name', 'required' => true]]);
    $this->get("/f/{$form}")->assertOk();

    postgresDown();
    app(VersionStore::class)->forgetLocal();

    $this->get("/f/{$form}")->assertOk()->assertSee('Name');
});
