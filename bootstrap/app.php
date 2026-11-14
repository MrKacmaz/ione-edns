<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\AssignRequestContext;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            // Every account has its own request budget, see the "api" rate limiter.
            Route::middleware(['api', 'auth:sanctum', 'throttle:api'])
                ->prefix('api/v1')
                ->name('api.v1.')
                ->group(base_path('routes/api.php'));

            // Public and session-free, for load balancers and container health checks.
            Route::middleware('api')
                ->get('health', HealthController::class)
                ->name('health');

            // API clients authorize their private WebSocket channels with their Sanctum token;
            // the dashboard uses /broadcasting/auth with its session.
            Broadcast::routes(['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum', 'throttle:api']]);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestContext::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
