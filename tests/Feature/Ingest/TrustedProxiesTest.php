<?php

use Illuminate\Support\Facades\Redis;

// The per-IP bucket (capacity 1 here) reveals which address the limiter keyed off: a second request from the same
// client IP is 429, from a different one 202.
function bootWithProxies(?string $proxies): void
{
    $_SERVER['TRUSTED_PROXIES'] = $proxies ?? '';
    test()->refreshApplication();
    config(['database.default' => 'pgsql_ingest', 'ingest.rate_limits.ip_form' => ['capacity' => 1, 'per_second' => 0.001]]);
    Redis::flushdb();
}

function submitFrom(string $socketIp, string $forwardedFor, string $form, array $body)
{
    return test()->withServerVariables(['REMOTE_ADDR' => $socketIp])
        ->withHeaders(['X-Forwarded-For' => $forwardedFor])
        ->postJson("/v1/forms/{$form}/submissions", $body);
}

afterEach(fn () => $_SERVER['TRUSTED_PROXIES'] = '');

// I13
it('ignores X-Forwarded-For and keys the limiter off the socket IP when no proxy is trusted', function () {
    bootWithProxies(null);
    ['form' => $form, 'version' => $version] = liveForm();

    submitFrom('10.0.0.1', '203.0.113.9', $form, payload($form, $version, ['email' => 'a@b.co']))->assertStatus(202);
    submitFrom('10.0.0.1', '198.51.100.7', $form, payload($form, $version, ['email' => 'a@b.co']))->assertStatus(429);
});

it('keys the limiter off the forwarded client IP when the request comes through a trusted proxy', function () {
    bootWithProxies('10.0.0.0/8');
    ['form' => $form, 'version' => $version] = liveForm();

    submitFrom('10.0.0.1', '203.0.113.9', $form, payload($form, $version, ['email' => 'a@b.co']))->assertStatus(202);
    submitFrom('10.0.0.1', '198.51.100.7', $form, payload($form, $version, ['email' => 'a@b.co']))->assertStatus(202);
    submitFrom('10.0.0.1', '198.51.100.7', $form, payload($form, $version, ['email' => 'a@b.co']))->assertStatus(429);
});

it('ignores a spoofed X-Forwarded-For from an address that is not a trusted proxy', function () {
    bootWithProxies('10.0.0.0/8');
    ['form' => $form, 'version' => $version] = liveForm();

    submitFrom('192.0.2.5', '203.0.113.9', $form, payload($form, $version, ['email' => 'a@b.co']))->assertStatus(202);
    submitFrom('192.0.2.5', '198.51.100.7', $form, payload($form, $version, ['email' => 'a@b.co']))->assertStatus(429);
});
