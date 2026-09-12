<?php

use App\Ingest\RateLimiter;
use Illuminate\Support\Facades\Log;

// I13
it('allows a burst up to capacity, then refuses with a wait, then refills', function () {
    $limiter = new RateLimiter;

    expect($limiter->hit('b', 3, 1.0))->toBeNull()
        ->and($limiter->hit('b', 3, 1.0))->toBeNull()
        ->and($limiter->hit('b', 3, 1.0))->toBeNull()
        ->and($limiter->hit('b', 3, 1.0))->toBeGreaterThanOrEqual(1)
        ->and($limiter->hit('other', 3, 1.0))->toBeNull();

    usleep(1_100_000);

    expect($limiter->hit('b', 3, 1.0))->toBeNull()
        ->and($limiter->hit('b', 3, 1.0))->toBeGreaterThanOrEqual(1);
});

it('is shared across instances through Redis', function () {
    expect((new RateLimiter)->hit('shared', 1, 0.001))->toBeNull()
        ->and((new RateLimiter)->hit('shared', 1, 0.001))->toBeGreaterThanOrEqual(1);
});

// I12
it('falls back to an in-worker bucket when Redis is unreachable, logging once per minute', function () {
    redisDown();
    Log::shouldReceive('warning')->once()->withArgs(fn ($message) => str_contains($message, 'redis unreachable'));

    $limiter = new RateLimiter;

    expect($limiter->hit('local', 2, 0.001))->toBeNull()
        ->and($limiter->hit('local', 2, 0.001))->toBeNull()
        ->and($limiter->hit('local', 2, 0.001))->toBeGreaterThanOrEqual(1)
        ->and((new RateLimiter)->hit('local', 2, 0.001))->toBeNull();
});
