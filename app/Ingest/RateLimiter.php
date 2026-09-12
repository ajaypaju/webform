<?php

namespace App\Ingest;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

// I13: token buckets shared across ingest instances through Redis, atomically (one Lua call per bucket).
// I12: if Redis is unreachable the worker limits from its own memory instead — never a 500, never a block.
final class RateLimiter
{
    private const LUA = <<<'LUA'
        local capacity, refill, now, cost = tonumber(ARGV[1]), tonumber(ARGV[2]), tonumber(ARGV[3]), tonumber(ARGV[4])
        local tokens, ts = capacity, now
        local bucket = redis.call('HMGET', KEYS[1], 'tokens', 'ts')
        if bucket[1] then tokens = tonumber(bucket[1]); ts = tonumber(bucket[2]) end
        tokens = math.min(capacity, tokens + math.max(0, now - ts) / 1000 * refill)
        local allowed, retry = 0, 0
        if tokens >= cost then tokens = tokens - cost; allowed = 1 else retry = math.ceil((cost - tokens) / refill) end
        redis.call('HSET', KEYS[1], 'tokens', tokens, 'ts', now)
        redis.call('PEXPIRE', KEYS[1], math.ceil(capacity / refill * 1000) + 1000)
        return {allowed, retry}
        LUA;

    /** @var array<string, array{tokens: float, ts: float}> in-worker buckets, used only while Redis is unreachable */
    private array $local = [];

    // WHY: static — one line per worker per minute, however many limiter instances the container hands out.
    private static int $lastLoggedAt = 0;

    /**
     * Take one token from the bucket.
     *
     * @return int|null null when allowed, otherwise the seconds to wait (for Retry-After)
     */
    public function hit(string $bucket, int $capacity, float $perSecond): ?int
    {
        $nowMs = (int) (microtime(true) * 1000);

        try {
            [$allowed, $retry] = Redis::eval(self::LUA, 1, "webform:rl:{$bucket}", $capacity, $perSecond, $nowMs, 1);
        } catch (Throwable $e) {
            $this->logOncePerMinute($e);

            return $this->hitLocal($bucket, $capacity, $perSecond, $nowMs);
        }

        return (int) $allowed === 1 ? null : max(1, (int) $retry);
    }

    private function hitLocal(string $bucket, int $capacity, float $perSecond, int $nowMs): ?int
    {
        $state = $this->local[$bucket] ?? ['tokens' => (float) $capacity, 'ts' => (float) $nowMs];
        $tokens = min($capacity, $state['tokens'] + max(0, $nowMs - $state['ts']) / 1000 * $perSecond);

        if ($tokens >= 1) {
            $this->local[$bucket] = ['tokens' => $tokens - 1, 'ts' => (float) $nowMs];

            return null;
        }

        $this->local[$bucket] = ['tokens' => $tokens, 'ts' => (float) $nowMs];

        return max(1, (int) ceil((1 - $tokens) / $perSecond));
    }

    private function logOncePerMinute(Throwable $e): void
    {
        if (time() - self::$lastLoggedAt >= 60) {
            self::$lastLoggedAt = time();
            Log::warning('rate limiter: redis unreachable, limiting per worker', ['error' => $e->getMessage()]);
        }
    }
}
