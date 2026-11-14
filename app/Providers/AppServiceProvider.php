<?php

namespace App\Providers;

use App\Http\ApiDocumentation\ApiOperationTransformer;
use App\Http\ApiDocumentation\NotificationRequestSchemaTransformer;
use App\Http\Pagination\CursorPaginator;
use App\Models\User;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\CursorPaginator as BaseCursorPaginator;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Paginate with a cursor that keeps the microseconds of dates.
        $this->app->bind(BaseCursorPaginator::class, CursorPaginator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimiting();
        $this->configureApiDocumentation();
    }

    /**
     * Let only operators reach the operator tools: the dashboard, its broadcast
     * channel and the system-wide metrics.
     */
    protected function configureAuthorization(): void
    {
        Gate::define('operate', static fn (User $user): bool => $user->is_operator);
    }

    /**
     * Give every account one API request budget, shared by all of its tokens, so
     * that issuing more tokens does not raise the limit.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', static fn (Request $request): Limit => Limit::perMinute(
            config()->integer('notifications.api.requests_per_minute'),
        )->by($request->user()?->id ?: $request->ip()));
    }

    /**
     * Document the application's API endpoints, which all expect a Sanctum bearer token.
     *
     * Only the named `api.v1.*` routes are documented; the WebSocket authorization
     * endpoint belongs to the broadcasting protocol and is described in the README.
     */
    protected function configureApiDocumentation(): void
    {
        Scramble::configure()
            ->routes(fn (Route $route): bool => str_starts_with((string) $route->getName(), 'api.v1.'))
            ->withDocumentTransformers([
                function (OpenApi $openApi): void {
                    $openApi->secure(SecurityScheme::http('bearer'));
                },
                NotificationRequestSchemaTransformer::class,
            ])
            ->withOperationTransformers(ApiOperationTransformer::class);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );
    }
}
