<?php

namespace App\Providers;

use App\Kafka\Producer;
use Illuminate\Support\ServiceProvider;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
