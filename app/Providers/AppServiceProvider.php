<?php

namespace App\Providers;

use App\Database\RuntimeRole;
use App\Ingest\RateLimiter;
use App\Ingest\RenderToken;
use App\Ingest\SubmissionProducer;
use App\Ingest\VersionStore;
use App\Kafka\Producer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter as LimiterFacade;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // WHY: singleton, not scoped. The producer holds no request state, and keeping its broker
        // connections open across Octane requests is the point: no TCP/metadata handshake per submission.
        $producer = fn () => new Producer(config('kafka.brokers'), config('kafka.producer'));
        $this->app->singleton(Producer::class, $producer);
        $this->app->singleton(SubmissionProducer::class, fn () => new SubmissionProducer(
            $producer, config('ingest.breaker.failures'), config('ingest.breaker.cooldown_seconds'), config('kafka.topics.submissions'),
        ));

        // WHY: singleton for the same reason — its worker-memory caches are the point (I12).
        $this->app->singleton(VersionStore::class);

        $this->app->singleton(RenderToken::class, fn () => new RenderToken((string) config('ingest.render_token_key')));

        // WHY: the fallback buckets and the once-a-minute log live in the instance; one per worker (config/octane.php warm).
        $this->app->singleton(RateLimiter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $role = config('app.role');

        // I13-adjacent: the login form takes an API key; brute force is bounded per IP.
        LimiterFacade::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));

        if ($role === 'ingest') {
            // WHY: only the HTTP process is guarded here; tests boot with APP_ROLE=ingest but connect as the owner.
            if (! $this->app->runningInConsole()) {
                RuntimeRole::checkOnFirstConnection($this->app['events'], $this->app['db'], $role);
            }

            return;
        }

        // WHY: artisan runs as whatever role the operator chose (migrations as the owner). Only long-running
        // processes serve tenants, so only they must prove they hold a least-privilege role. Octane boots once per worker.
        if ($this->app->runningInConsole() && $role !== 'consumer') {
            return;
        }

        try {
            RuntimeRole::assertForAppRole(DB::connection(), $role);
        } catch (RuntimeException $e) {
            RuntimeRole::refuse($e);
        }
    }

}
