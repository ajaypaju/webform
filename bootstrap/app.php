<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // WHY: one image, two processes. Each role registers only its own routes so a burst on
        // ingest can't starve the dashboard, and the control plane is never reachable on the public origin.
        then: function (Application $app): void {
            $role = env('APP_ROLE');

            // WHY: artisan (image build, key:generate, the consumer) serves no HTTP, so it needs no role.
            if ($role === null && $app->runningInConsole()) {
                return;
            }

            match ($role) {
                'api' => Route::middleware('api')->group(__DIR__.'/../routes/api.php'),
                'ingest' => Route::middleware('api')->group(__DIR__.'/../routes/public.php'),
                default => throw new RuntimeException('APP_ROLE must be "api" or "ingest".'),
            };
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
