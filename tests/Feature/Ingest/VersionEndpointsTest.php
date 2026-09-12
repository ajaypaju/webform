<?php

use App\Ingest\VersionStore;
use Illuminate\Support\Facades\Redis;

it('serves a published version as immutable JSON without the tenant id', function () {
    $tenant = tenant();
    ['form' => $form, 'version' => $version] = form($tenant);

    $this->getJson("/v1/forms/{$form}/versions/{$version}")
        ->assertOk()
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
        ->assertJson(['id' => $version, 'form_id' => $form, 'version_no' => 1, 'definition' => ['fields' => []]])
        ->assertJsonMissing(['tenant_id' => $tenant]);

    $this->getJson("/v1/forms/{$form}")
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=30, public, stale-while-revalidate=60')
        ->assertExactJson(['current_version_id' => $version]);
});

it('returns 404 for a version of another form, a draft-only form, an archived form, and malformed ids', function () {
    ['form' => $formA, 'version' => $versionA] = form(tenant());
    ['form' => $formB] = form(tenant());
    ['form' => $draft] = form(tenant(), 'draft');
    ['form' => $archived, 'version' => $archivedVersion] = form(tenant(), 'archived');

    $this->getJson("/v1/forms/{$formB}/versions/{$versionA}")->assertNotFound();
    $this->getJson("/v1/forms/{$draft}")->assertNotFound();
    $this->getJson("/v1/forms/{$archived}")->assertNotFound();
    $this->getJson("/v1/forms/{$archived}/versions/{$archivedVersion}")->assertNotFound();
    $this->getJson("/v1/forms/not-a-uuid/versions/{$versionA}")->assertNotFound();
    $this->getJson("/v1/forms/{$formA}/versions/nope")->assertNotFound();
});

// I12
it('keeps serving from Redis after PostgreSQL becomes unreachable, and returns 503 for a cold key when both are down', function () {
    ['form' => $form, 'version' => $version] = form(tenant());
    ['form' => $coldForm, 'version' => $coldVersion] = form(tenant());

    $this->getJson("/v1/forms/{$form}/versions/{$version}")->assertOk();
    $this->getJson("/v1/forms/{$form}")->assertOk();
    expect(Redis::get(VersionStore::versionKey($form, $version)))->not->toBeNull()
        ->and(Redis::get(VersionStore::formKey($form)))->not->toBeNull();

    postgresDown();
    app(VersionStore::class)->forgetLocal();

    $this->getJson("/v1/forms/{$form}/versions/{$version}")->assertOk()->assertJson(['id' => $version]);
    $this->getJson("/v1/forms/{$form}")->assertOk()->assertExactJson(['current_version_id' => $version]);

    Redis::flushdb();
    redisDown();
    app(VersionStore::class)->forgetLocal();

    $this->getJson("/v1/forms/{$coldForm}/versions/{$coldVersion}")->assertStatus(503)->assertHeader('Retry-After', '5');
    $this->getJson("/v1/forms/{$coldForm}")->assertStatus(503)->assertHeader('Retry-After', '5');
});

it('serves stale form state from worker memory when PostgreSQL and Redis are both gone', function () {
    ['form' => $form, 'version' => $version] = form(tenant());
    $this->getJson("/v1/forms/{$form}")->assertOk();

    Redis::flushdb();
    redisDown();
    postgresDown();
    $this->travel(VersionStore::FORM_STATE_WORKER_TTL + 1)->seconds();

    $this->getJson("/v1/forms/{$form}")->assertOk()->assertExactJson(['current_version_id' => $version]);
});
