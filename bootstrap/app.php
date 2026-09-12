<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\DashboardTenant;
use App\Http\Middleware\PublicHeaders;
use App\Http\ValidationFailed;
use App\Ingest\BrokerUnavailable;
use App\Ingest\RateLimited;
use App\Ingest\StoreUnavailable;
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
                'api' => [
                    Route::middleware('api')->group(__DIR__.'/../routes/api.php'),
                    Route::middleware('web')->group(__DIR__.'/../routes/dashboard.php'),
                ],
                'ingest' => Route::middleware('api')->group(__DIR__.'/../routes/public.php'),
                'consumer' => null,
                default => throw new RuntimeException('APP_ROLE must be "api", "ingest" or "consumer".'),
            };
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['api-key' => AuthenticateApiKey::class, 'dashboard' => DashboardTenant::class]);

        // I9: global for the public origin so 404s and errors carry the headers too; route middleware never runs
        // for an unmatched path.
        if (env('APP_ROLE') === 'ingest') {
            $middleware->append(PublicHeaders::class);

            // I13: the per-IP bucket must see the visitor, not the load balancer. Unset = trust nobody, so a spoofed
            // X-Forwarded-For from the open internet is ignored.
            if (($proxies = trim((string) env('TRUSTED_PROXIES'))) !== '') {
                $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
            }
        }

        // WHY: route-model binding must run inside the tenant transaction, after set_config, or RLS returns no rows.
        $middleware->prependToPriorityList(SubstituteBindings::class, AuthenticateApiKey::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, DashboardTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('v1/*') || $request->expectsJson(),
        );

        // WHY: these are answers (413/422/429/503), not faults. Reporting them wrote a stack trace per refused
        // request under load; the breaker and store log their own state changes once.
        $exceptions->dontReport([ValidationFailed::class, RateLimited::class, BrokerUnavailable::class, StoreUnavailable::class]);

        // I12: the store had no reachable source for a cold key; the client should retry, not report a bug.
        $exceptions->render(fn (StoreUnavailable $e) => response()->json(['error' => 'unavailable'], 503, ['Retry-After' => '5']));

        // I1: nothing was acked; retry with the same submission id.
        $exceptions->render(fn (BrokerUnavailable $e) => response()->json(['error' => 'unavailable'], 503, ['Retry-After' => '5']));

        // I13
        $exceptions->render(fn (RateLimited $e) => response()->json(['error' => 'rate_limited'], 429, ['Retry-After' => (string) $e->retryAfter]));

        // 422s carry codes, never messages, on both the control plane and the public API.
        $exceptions->render(fn (ValidationFailed $e) => response()->json(['errors' => $e->errors], $e->status));
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
