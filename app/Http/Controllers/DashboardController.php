<?php

namespace App\Http\Controllers;

use App\Http\Resources\NotificationResource;
use App\Models\User;
use App\Services\Observability\MetricsCollector;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The number of the operator's latest notifications the feed starts with.
     */
    private const int RECENT_NOTIFICATIONS = 20;

    /**
     * Show the live operator dashboard.
     *
     * The page starts from the current metrics snapshot and the operator's latest
     * notifications; both are then kept current over the WebSocket connection.
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, MetricsCollector $collector): Response
    {
        return Inertia::render('Dashboard', [
            'metrics' => $collector->snapshot()->toArray(),
            'notifications' => NotificationResource::collection(
                $user->notifications()->latest()->latest('id')->limit(self::RECENT_NOTIFICATIONS)->get(),
            )->resolve($request),
        ]);
    }
}
