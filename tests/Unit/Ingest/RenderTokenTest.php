<?php

use App\Ingest\RenderToken;

// I13
it('issues a token that verifies for its form and version and returns issued_at', function () {
    $tokens = new RenderToken('k1');
    $token = $tokens->issue('form-a', 'version-1', 1_700_000_000);

    expect($tokens->verify($token, 'form-a', 'version-1'))->toBe(1_700_000_000)
        ->and($token)->toMatch('/^[A-Za-z0-9_-]+$/');
});

it('rejects a token for another form or version, a tampered token, garbage, and a rotated key', function () {
    $tokens = new RenderToken('k1');
    $token = $tokens->issue('form-a', 'version-1');

    expect($tokens->verify($token, 'form-b', 'version-1'))->toBeNull()
        ->and($tokens->verify($token, 'form-a', 'version-2'))->toBeNull()
        ->and($tokens->verify(substr($token, 0, -2).'zz', 'form-a', 'version-1'))->toBeNull()
        ->and($tokens->verify('garbage', 'form-a', 'version-1'))->toBeNull()
        ->and($tokens->verify('', 'form-a', 'version-1'))->toBeNull()
        ->and((new RenderToken('k2'))->verify($token, 'form-a', 'version-1'))->toBeNull();
});

it('refuses to run without a key', function () {
    expect(fn () => new RenderToken(''))->toThrow(RuntimeException::class, 'RENDER_TOKEN_KEY');
});
