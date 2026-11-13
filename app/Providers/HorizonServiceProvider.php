<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Configure who may open Horizon.
     *
     * Unlike Horizon's default, the local environment gets no exception: only
     * operators see the dashboard, wherever it runs.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(static fn (Request $request): bool => Gate::forUser($request->user())->allows('viewHorizon'));
    }

    /**
     * Register the Horizon gate.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', static fn (User $user): bool => $user->is_operator);
    }
}
