<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->tenant = tenant();
    $this->session = ['tenant_id' => $this->tenant];
});

function createViaDashboard($test, string $name): string
{
    $test->withSession($test->session)->post('/dashboard/forms', ['name' => $name])->assertRedirect();

    return DB::connection('pgsql')->table('forms')->where('name', $name)->value('id');
}

it('lists forms, creates one, and shows the builder with the state in a JSON data block', function () {
    $form = createViaDashboard($this, 'Signup');

    $this->withSession($this->session)->get('/dashboard')->assertOk()->assertSee('Signup')->assertSee("/dashboard/forms/{$form}");

    $html = $this->withSession($this->session)->get("/dashboard/forms/{$form}")->assertOk()->getContent();
    expect($html)->toContain('<script type="application/json" id="builder-data">')
        ->toContain('data-preview')
        ->toMatch('~<script type="module" src="http://localhost:8000/build/assets/main-[^"]+\.js"></script>~')
        ->not->toContain('<script>');

    preg_match('#id="builder-data">(.*?)</script>#s', $html, $m);
    $state = json_decode($m[1], true);
    expect($state['form'])->toMatchArray(['id' => $form, 'name' => 'Signup', 'status' => 'draft', 'current_version' => null])
        ->and($state['form']['page_url'])->toBe("http://localhost:8080/f/{$form}")
        ->and($state['draft'])->toBe(['fields' => []])
        ->and($state['csrf'])->toBeString();
});

// I5: ids are minted server-side once; advisory errors come back per field and for the whole definition.
it('saves an incomplete draft, assigns ids to new fields, and reports errors per field', function () {
    $form = createViaDashboard($this, 'Signup');

    $response = $this->withSession($this->session)->putJson("/dashboard/forms/{$form}/draft", ['definition' => ['fields' => [
        ['id' => null, 'type' => 'email', 'label' => 'Work Email', 'required' => true],
        ['id' => null, 'type' => 'select', 'label' => 'Plan', 'required' => false],
        ['id' => null, 'type' => 'text', 'label' => 'Work Email', 'required' => false, 'visible_if' => ['field' => 'nope', 'op' => 'eq', 'value' => 'x']],
    ]]])->assertOk();

    $ids = array_column($response->json('definition.fields'), 'id');
    expect($ids)->toBe(['work_email', 'plan', 'work_email_2'])
        ->and($response->json('definition_errors'))->toBe(['options_missing', 'visible_if_field'])
        ->and($response->json('field_errors'))->toBe(['plan' => ['options_missing'], 'work_email_2' => ['visible_if_field']]);

    // The stored draft carries the assigned ids, and a second save keeps them.
    expect(json_decode(DB::connection('pgsql')->table('forms')->where('id', $form)->value('draft'), true)['fields'][0]['id'])->toBe('work_email');
    $again = $this->withSession($this->session)->putJson("/dashboard/forms/{$form}/draft", ['definition' => $response->json('definition')])->assertOk();
    expect(array_column($again->json('definition.fields'), 'id'))->toBe($ids);
});

// I5: publish goes through the same FormEditor the API uses; a type change is refused against the field.
it('publishes a valid draft and then blocks a type change with the error attached to the field', function () {
    $form = createViaDashboard($this, 'Signup');
    $email = ['id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true];

    $this->withSession($this->session)->putJson("/dashboard/forms/{$form}/draft", ['definition' => ['fields' => [$email]]])->assertOk();
    $this->withSession($this->session)->postJson("/dashboard/forms/{$form}/publish")->assertCreated()
        ->assertJson(['version_no' => 1, 'page_url' => "http://localhost:8080/f/{$form}"]);

    $this->withSession($this->session)->putJson("/dashboard/forms/{$form}/draft", ['definition' => ['fields' => [[...$email, 'type' => 'text']]]])->assertOk();
    $this->withSession($this->session)->postJson("/dashboard/forms/{$form}/publish")
        ->assertStatus(422)
        ->assertExactJson(['errors' => [['field' => 'email', 'code' => 'type_changed']]]);

    $this->withSession($this->session)->putJson("/dashboard/forms/{$form}/draft", ['definition' => ['fields' => [['id' => 'Bad Id', 'type' => 'text', 'label' => 'x', 'required' => false]]]])->assertOk();
    $this->withSession($this->session)->postJson("/dashboard/forms/{$form}/publish")->assertStatus(422)->assertExactJson(['errors' => [['code' => 'field_id']]]);

    expect(DB::connection('pgsql')->table('form_versions')->where('form_id', $form)->count())->toBe(1);
    $state = json_decode(preg_replace('#.*id="builder-data">(.*?)</script>.*#s', '$1', $this->withSession($this->session)->get("/dashboard/forms/{$form}")->getContent()), true);
    expect($state['versions'][0])->toMatchArray(['version_no' => 1, 'definition' => ['fields' => [$email]]]);
});

// I9: the dashboard renders tenant content; a hostile name and label must be inert in markup and in the data block.
it('renders a hostile form name and field label inert', function () {
    $name = '<img src=x onerror=alert(1)>';
    $form = createViaDashboard($this, $name);
    $this->withSession($this->session)->putJson("/dashboard/forms/{$form}/draft", ['definition' => ['fields' => [
        ['id' => 'x', 'type' => 'text', 'label' => '</script><script>alert(2)</script>', 'required' => false],
    ]]])->assertOk();

    foreach (['/dashboard', "/dashboard/forms/{$form}"] as $path) {
        $html = $this->withSession($this->session)->get($path)->assertOk()->getContent();
        expect($html)->toContain('&lt;img src=x onerror=alert(1)&gt;')->not->toContain('<img src=x');
    }

    // The label reaches the browser only inside the JSON data block, hex-escaped so it cannot close the block.
    $html = $this->withSession($this->session)->get("/dashboard/forms/{$form}")->getContent();
    expect($html)->toContain('\\u003C\\/script\\u003E\\u003Cscript\\u003Ealert(2)')
        ->not->toContain('</script><script>alert(2)');
});
