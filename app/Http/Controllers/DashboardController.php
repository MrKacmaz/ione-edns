<?php

namespace App\Http\Controllers;

use App\Enums\NotificationChannel;
use App\Http\Resources\NotificationResource;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Services\Delivery\Contracts\ChannelRateLimiter;
use App\Services\Observability\MetricsCollector;
use Illuminate\Container\Attributes\Config;
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
     * The send form offers the operator's templates with their variables, and each
     * channel shows its rate limit; a limit of 0 means the channel is paused.
     *
     * @param  int  $maxBatchSize  The most notifications the send form may create at once.
     * @param  string|null  $jaegerUrl  The tracing UI the feed links each notification's trace to.
     */
    public function __invoke(
        Request $request,
        #[CurrentUser] User $user,
        MetricsCollector $collector,
        ChannelRateLimiter $rateLimiter,
        #[Config('notifications.batch.max_size')] int $maxBatchSize,
        #[Config('services.jaeger.ui_url')] ?string $jaegerUrl,
    ): Response {
        return Inertia::render('Dashboard', [
            'metrics' => $collector->snapshot()->toArray(),
            'notifications' => NotificationResource::collection(
                $user->notifications()->latest()->latest('id')->limit(self::RECENT_NOTIFICATIONS)->get(),
            )->resolve($request),
            'templates' => $user->notificationTemplates()->orderBy('name')->get()->map(
                fn (NotificationTemplate $template): array => [
                    'name' => $template->name,
                    'channel' => $template->channel->value,
                    'variables' => $template->variables(),
                ],
            ),
            'maxBatchSize' => $maxBatchSize,
            'rateLimits' => array_combine(
                array_map(fn (NotificationChannel $channel): string => $channel->value, NotificationChannel::cases()),
                array_map($rateLimiter->limitPerSecond(...), NotificationChannel::cases()),
            ),
            'jaegerUrl' => $jaegerUrl === null || $jaegerUrl === '' ? null : rtrim($jaegerUrl, '/'),
        ]);
    }
}
