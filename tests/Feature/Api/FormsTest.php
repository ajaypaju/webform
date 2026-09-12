<?php

use App\Ingest\VersionStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

function field(string $id, string $type, array $extra = []): array
{
    return ['id' => $id, 'type' => $type, 'label' => Str::title($id), 'required' => false] + $extra;
}

function definition(array ...$fields): array
{
    return ['fields' => $fields];
}

function createForm(string $key, ?array $definition = null): string
{
    return test()->withToken($key)->postJson('/v1/forms', ['name' => 'Signup'] + ($definition ? ['definition' => $definition] : []))
        ->assertCreated()->json('id');
}

function publish(string $key, string $formId, array $definition)
{
    test()->withToken($key)->putJson("/v1/forms/{$formId}/draft", ['definition' => $definition])->assertOk();

    return test()->withToken($key)->postJson("/v1/forms/{$formId}/publish");
}

beforeEach(function () {
    $this->tenant = tenant();
    $this->key = apiKey($this->tenant);
});

it('creates, shows and lists forms with keyset pagination', function () {
    $first = createForm($this->key);
    $second = createForm($this->key, definition(field('email', 'email')));

    $this->withToken($this->key)->getJson("/v1/forms/{$second}")
        ->assertOk()
        ->assertJson(['id' => $second, 'name' => 'Signup', 'status' => 'draft', 'current_version' => null, 'draft' => definition(field('email', 'email'))]);

    $page1 = $this->withToken($this->key)->getJson('/v1/forms?limit=1')->assertOk();
    $page2 = $this->withToken($this->key)->getJson('/v1/forms?limit=1&cursor='.$page1->json('next_cursor'))->assertOk();

    expect($page1->json('data.0.id'))->toBe($second)
        ->and($page2->json('data.0.id'))->toBe($first)
        ->and($page2->json('next_cursor'))->toBeNull();

    $this->withToken($this->key)->getJson('/v1/forms?cursor=garbage')->assertStatus(422)->assertExactJson(['errors' => [['code' => 'cursor', 'field' => 'cursor']]]);
});

it('returns 422 with codes for a malformed envelope', function () {
    $this->withToken($this->key)->postJson('/v1/forms', ['definition' => 'nope'])
        ->assertStatus(422)
        ->assertExactJson(['errors' => [['code' => 'required', 'field' => 'name'], ['code' => 'array', 'field' => 'definition']]]);
});

it('returns 404, never 500, for a malformed form id', function () {
    foreach ([['get', ''], ['put', '/draft'], ['post', '/publish'], ['get', '/versions']] as [$method, $suffix]) {
        $this->withToken($this->key)->json($method, "/v1/forms/not-a-uuid{$suffix}", ['definition' => definition()])->assertNotFound();
    }
});

// I11
it("returns 404 for another tenant's form on every endpoint", function () {
    $form = createForm($this->key);
    $other = apiKey(tenant());

    $this->withToken($other)->getJson("/v1/forms/{$form}")->assertNotFound();
    $this->withToken($other)->putJson("/v1/forms/{$form}/draft", ['definition' => definition()])->assertNotFound();
    $this->withToken($other)->postJson("/v1/forms/{$form}/publish")->assertNotFound();
    $this->withToken($other)->getJson("/v1/forms/{$form}/versions")->assertNotFound();
    expect($this->withToken($other)->getJson('/v1/forms')->json('data'))->toBe([]);
});

it('saves an incomplete draft and reports definition errors as advisory', function () {
    $form = createForm($this->key);
    $draft = definition(['id' => 'Bad Id', 'type' => 'text', 'label' => 'x', 'required' => false], field('s', 'select'));

    $this->withToken($this->key)->putJson("/v1/forms/{$form}/draft", ['definition' => $draft])
        ->assertOk()
        ->assertJson(['draft' => $draft, 'definition_errors' => ['field_id', 'options_missing']]);

    $this->withToken($this->key)->postJson("/v1/forms/{$form}/publish")
        ->assertStatus(422)
        ->assertExactJson(['errors' => [['code' => 'field_id'], ['code' => 'options_missing']]]);
});

// I4, I5
it('publishes v1 then v2, and later draft edits leave both versions unchanged', function () {
    $form = createForm($this->key);
    $v1 = definition(field('email', 'email', ['required' => true]));
    $v2 = definition(field('email', 'email'), field('age', 'number'));

    publish($this->key, $form, $v1)->assertCreated()->assertJson(['version_no' => 1]);
    publish($this->key, $form, $v2)->assertCreated()->assertJson(['version_no' => 2]);
    $this->withToken($this->key)->putJson("/v1/forms/{$form}/draft", ['definition' => definition(field('email', 'email'), field('notes', 'text'))])->assertOk();

    $versions = $this->withToken($this->key)->getJson("/v1/forms/{$form}/versions")->assertOk()->json('data');
    expect(array_column($versions, 'version_no'))->toBe([1, 2]);

    $stored = DB::connection('pgsql')->table('form_versions')->where('form_id', $form)->orderBy('version_no')->pluck('definition')->map(fn ($d) => json_decode($d, true))->all();
    expect($stored)->toBe([$v1, $v2]);

    $this->withToken($this->key)->getJson("/v1/forms/{$form}")->assertJson(['status' => 'published', 'current_version' => ['version_no' => 2]]);
});

// I5
it('rejects a publish that changes the type of a field id from any prior version, including delete-then-re-add', function () {
    $form = createForm($this->key);

    publish($this->key, $form, definition(field('a', 'text'), field('b', 'number')))->assertCreated();
    publish($this->key, $form, definition(field('a', 'text')))->assertCreated();

    publish($this->key, $form, definition(field('a', 'text'), field('b', 'text')))
        ->assertStatus(422)
        ->assertExactJson(['errors' => [['field' => 'b', 'code' => 'type_changed']]]);

    publish($this->key, $form, definition(field('a', 'email')))
        ->assertStatus(422)
        ->assertExactJson(['errors' => [['field' => 'a', 'code' => 'type_changed']]]);

    expect($this->withToken($this->key)->getJson("/v1/forms/{$form}/versions")->json('data'))->toHaveCount(2);
});

it('rejects oversized drafts without a 500', function () {
    $form = createForm($this->key);
    $big = definition(field('t', 'text', ['help_text' => str_repeat('x', 270_000)]));
    $manyFields = definition(...array_map(fn ($i) => field("f{$i}", 'text'), range(1, 201)));
    $manyOptions = definition(field('s', 'select', ['options' => array_map(fn ($i) => ['value' => "o{$i}", 'label' => "O{$i}"], range(1, 101))]));

    $this->withToken($this->key)->putJson("/v1/forms/{$form}/draft", ['definition' => $big])
        ->assertStatus(413)->assertExactJson(['errors' => [['code' => 'body_too_large']]]);
    $this->withToken($this->key)->putJson("/v1/forms/{$form}/draft", ['definition' => $manyFields])
        ->assertStatus(422)->assertExactJson(['errors' => [['code' => 'too_many_fields', 'field' => 'definition']]]);
    $this->withToken($this->key)->putJson("/v1/forms/{$form}/draft", ['definition' => $manyOptions])
        ->assertStatus(422)->assertExactJson(['errors' => [['code' => 'too_many_options', 'field' => 's']]]);
    $this->withToken($this->key)->postJson('/v1/forms', ['name' => 'x', 'definition' => $big])
        ->assertStatus(413)->assertExactJson(['errors' => [['code' => 'body_too_large']]]);
});

// I12
it('writes the version and form state to Redis on publish, and still publishes when Redis is down', function () {
    $form = createForm($this->key);
    $v1 = publish($this->key, $form, definition(field('email', 'email')))->assertCreated()->json('version_id');

    expect(json_decode(Redis::get(VersionStore::versionKey($form, $v1)), true))->toMatchArray(['id' => $v1, 'version_no' => 1, 'definition' => definition(field('email', 'email'))])
        ->and(json_decode(Redis::get(VersionStore::formKey($form)), true))->toMatchArray(['status' => 'published', 'current_version_id' => $v1]);

    redisDown();
    Log::shouldReceive('warning')->atLeast()->once()->withArgs(fn ($message) => str_contains($message, 'redis write failed'));

    publish($this->key, $form, definition(field('email', 'email'), field('age', 'number')))->assertCreated()->assertJson(['version_no' => 2]);
});
