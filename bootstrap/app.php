<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\ValidationFailed;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        $middleware->alias(['api-key' => AuthenticateApiKey::class]);

        // WHY: route-model binding must run inside the api-key transaction, after set_config, or RLS returns no rows.
        $middleware->prependToPriorityList(SubstituteBindings::class, AuthenticateApiKey::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->expectsJson(),
        );

        // 422s carry codes, never messages, on both the control plane and the public API.
        $exceptions->render(fn (ValidationFailed $e) => response()->json(['errors' => $e->errors], 422));
        $exceptions->render(function (ValidationException $e) {
            $errors = [];

            foreach ($e->validator->failed() as $field => $rules) {
                foreach (array_keys($rules) as $rule) {
                    $errors[] = ['code' => Str::snake(class_basename($rule)), 'field' => $field];
                }
            }

            return response()->json(['errors' => $errors], 422);
        });
    })->create();
