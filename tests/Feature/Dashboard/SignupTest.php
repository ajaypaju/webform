<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

const SIGNUP = ['email' => 'Ada@Example.com', 'password' => 'correct horse battery', 'company_name' => 'Acme'];

function owner(): object
{
    return DB::connection('pgsql')->table('users')->join('tenant_users', 'tenant_users.user_id', '=', 'users.id')
        ->join('tenants', 'tenants.id', '=', 'tenant_users.tenant_id')->join('api_keys', 'api_keys.tenant_id', '=', 'tenants.id')
        ->first(['users.id as user_id', 'users.email', 'users.email_verified_at', 'tenants.id as tenant_id', 'tenants.name', 'tenant_users.role', 'api_keys.prefix', 'api_keys.key_hash']);
}

it('creates user, tenant, owner membership and first key in one go, logs in, and logs the verification link', function () {
    Log::spy();

    $this->get('/signup')->assertOk()->assertSee('Create workspace');
    $this->post('/signup', SIGNUP)->assertRedirect('/signup/done');

    $row = owner();
    expect($row->email)->toBe('Ada@Example.com')->and($row->name)->toBe('Acme')->and($row->role)->toBe('owner')
        ->and($row->email_verified_at)->toBeNull()->and($row->prefix)->toStartWith('wf_')->and(strlen($row->prefix))->toBe(8)
        ->and(session('user_id'))->toBe($row->user_id)->and(session('tenant_id'))->toBe($row->tenant_id)
        ->and(DB::connection('pgsql')->table('users')->count())->toBe(1);

    Log::shouldHaveReceived('info')->once()->withArgs(fn ($message, $context) => str_starts_with($message, 'verification link')
        && $context['email'] === 'Ada@Example.com' && str_contains($context['url'], "/verify/{$row->user_id}/") && str_contains($context['url'], 'signature='));

    $this->get('/signup/done')->assertOk()->assertSee('Ada@Example.com')->assertSee('designed, not built');
    $this->get('/dashboard')->assertOk()->assertSee('not verified yet');
});

it('leaves nothing behind when a step in the middle fails', function () {
    // WHY: a real mid-transaction failure — after the user and tenant rows, the membership insert violates a
    // constraint added for this test — not a mocked exception.
    DB::connection('pgsql')->statement("alter table tenant_users add constraint break_signup check (role <> 'owner')");

    try {
        $this->post('/signup', SIGNUP)->assertStatus(500);
    } finally {
        DB::connection('pgsql')->statement('alter table tenant_users drop constraint break_signup');
    }

    foreach (['users', 'tenants', 'tenant_users', 'api_keys'] as $table) {
        expect(DB::connection('pgsql')->table($table)->count())->toBe(0, $table);
    }
    expect(session()->has('user_id'))->toBeFalse();
});

it('gives an existing email the same response as a new one, and tells the owner by logged email', function () {
    user(tenant(), false);
    $this->post('/signup', SIGNUP)->assertRedirect('/signup/done');
    $fresh = $this->get('/signup/done')->assertOk()->getContent();
    $this->flushSession();

    Log::spy();
    $this->post('/signup', [...SIGNUP, 'email' => 'ada@example.com', 'company_name' => 'Other'])->assertRedirect('/signup/done');
    $again = $this->get('/signup/done')->assertOk()->getContent();

    // Same page, byte for byte apart from the CSRF token and the address as typed; no second account; no session.
    $normalise = fn ($html) => preg_replace(['/name="csrf-token" content="[^"]*"/', '/ada@example\.com/i', '/name="_token" value="[^"]*"/'], ['', 'EMAIL', ''], $html);
    expect($normalise($again))->toBe($normalise($fresh))
        ->and(DB::connection('pgsql')->table('users')->count())->toBe(2)
        ->and(DB::connection('pgsql')->table('tenants')->where('name', 'Other')->exists())->toBeFalse()
        ->and(session()->has('user_id'))->toBeFalse();
    Log::shouldHaveReceived('info')->once()->withArgs(fn ($message, $context) => str_starts_with($message, 'signup with an existing email') && $context['email'] === 'ada@example.com');
    $this->get('/dashboard')->assertRedirect('/login');
});

it('rejects short passwords and rate-limits signups per ip', function () {
    $this->from('/signup')->post('/signup', [...SIGNUP, 'password' => 'short'])->assertRedirect('/signup')->assertSessionHasErrors('password');
    expect(DB::connection('pgsql')->table('users')->count())->toBe(0);

    foreach (range(2, 5) as $i) {
        $this->post('/signup', [...SIGNUP, 'email' => "u{$i}@example.com"])->assertRedirect();
    }
    $this->post('/signup', [...SIGNUP, 'email' => 'u6@example.com'])->assertStatus(429);
});

it('gates publishing and key creation on a verified address, and the logged link verifies it', function () {
    Log::spy();
    $this->post('/signup', SIGNUP)->assertRedirect('/signup/done');
    $row = owner();
    $url = null;
    Log::shouldHaveReceived('info')->withArgs(function ($message, $context) use (&$url) {
        if (str_starts_with($message, 'verification link')) {
            $url = $context['url'];
        }

        return true;
    });

    $session = ['tenant_id' => $row->tenant_id, 'user_id' => $row->user_id];
    $this->withSession($session)->post('/dashboard/forms', ['name' => 'Signup'])->assertRedirect();
    $form = DB::connection('pgsql')->table('forms')->value('id');
    $this->withSession($session)->putJson("/dashboard/forms/{$form}/draft", ['definition' => ['fields' => [['id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true]]]])->assertOk();

    $this->withSession($session)->postJson("/dashboard/forms/{$form}/publish")->assertStatus(403)->assertExactJson(['error' => 'email_unverified']);
    $this->withSession($session)->post('/dashboard/api-keys')->assertStatus(403);
    expect(DB::connection('pgsql')->table('api_keys')->count())->toBe(1);

    // Resend writes the link again, and is capped per ip.
    foreach (range(1, 3) as $i) {
        $this->withSession($session)->from('/dashboard')->post('/dashboard/verify/resend')->assertRedirect('/dashboard');
    }
    $this->withSession($session)->post('/dashboard/verify/resend')->assertStatus(429);
    Log::shouldHaveReceived('info')->times(4)->withArgs(fn ($m) => str_starts_with($m, 'verification link'));

    // A tampered link is refused; the signed one verifies.
    $this->get(preg_replace('/signature=\w+/', 'signature=0', $url))->assertStatus(403);
    $this->withSession($session)->get($url)->assertRedirect('/dashboard');
    expect(DB::connection('pgsql')->table('users')->value('email_verified_at'))->not->toBeNull();

    $this->withSession($session)->get('/dashboard')->assertOk()->assertDontSee('not verified yet');
    $this->withSession($session)->postJson("/dashboard/forms/{$form}/publish")->assertCreated();
    $this->withSession($session)->post('/dashboard/api-keys')->assertRedirect('/dashboard/api-keys');
    expect(DB::connection('pgsql')->table('api_keys')->count())->toBe(2);
});

// Only a local build shows the link on screen; anywhere else it is a log line (a mail, once there is a transport).
it('shows the verification link in the banner only in a local build', function () {
    $this->post('/signup', SIGNUP)->assertRedirect('/signup/done');
    $row = owner();
    $session = ['tenant_id' => $row->tenant_id, 'user_id' => $row->user_id];

    $html = $this->withSession($session)->get('/dashboard')->assertOk()->getContent();
    expect($html)->toContain('Write a new link to the log')->not->toContain('data-verify-link');

    $this->app['env'] = 'local';
    try {
        $html = $this->withSession($session)->get('/dashboard')->assertOk()->getContent();
        preg_match('#<a href="([^"]+)" data-verify-link>#', $html, $m);
        expect($html)->not->toContain('Write a new link to the log');
        $this->withSession($session)->get(html_entity_decode($m[1]))->assertRedirect('/dashboard');
    } finally {
        $this->app['env'] = 'testing';
    }

    expect(DB::connection('pgsql')->table('users')->value('email_verified_at'))->not->toBeNull();
    $this->withSession($session)->get('/dashboard')->assertOk()->assertDontSee('not verified yet');
});
