<?php

namespace App\Providers;

use App\Database\RuntimeRole;
use App\Ingest\RenderToken;
use App\Ingest\VersionStore;
use App\Kafka\Producer;
use Illuminate\Support\Facades\DB;
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
        $this->app->singleton(Producer::class, fn () => new Producer(
            config('kafka.brokers'),
            config('kafka.producer'),
        ));

        // WHY: singleton for the same reason — its worker-memory caches are the point (I12).
        $this->app->singleton(VersionStore::class);

        $this->app->singleton(RenderToken::class, fn () => new RenderToken((string) config('ingest.render_token_key')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $role = config('app.role');

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
