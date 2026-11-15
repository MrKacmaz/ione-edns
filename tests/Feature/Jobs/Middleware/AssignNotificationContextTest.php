<?php

use App\Enums\NotificationChannel;
use App\Jobs\Middleware\AssignNotificationContext;
use App\Jobs\SendNotificationJob;
use Illuminate\Support\Facades\Context;

/**
 * Run the middleware for the job, and return the log context the job sees.
 *
 * @return array<string, mixed>
 */
function contextSeenByJob(SendNotificationJob $job): array
{
    $seen = [];

    (new AssignNotificationContext)->handle($job, function () use (&$seen): void {
        $seen = Context::all();
    });

    return $seen;
}

test('logs the job under its notification\'s id and correlation id, instead of those of whoever pushed it', function () {
    Context::add('correlation_id', 'f6b2a4e0-0d1c-4f53-9c1e-2a7b8c9d0e1f');

    $context = contextSeenByJob(new SendNotificationJob('notification-id', NotificationChannel::Sms, '0195d3c2-7a8e-7c4b-9a1e-3f6b2d8c4e10'));

    expect($context)->toBe([
        'correlation_id' => '0195d3c2-7a8e-7c4b-9a1e-3f6b2d8c4e10',
        'notification_id' => 'notification-id',
    ]);
});

test('keeps the pushing request\'s correlation id when the notification has none', function () {
    Context::add('correlation_id', 'f6b2a4e0-0d1c-4f53-9c1e-2a7b8c9d0e1f');

    $context = contextSeenByJob(new SendNotificationJob('notification-id', NotificationChannel::Sms));

    expect($context)->correlation_id->toBe('f6b2a4e0-0d1c-4f53-9c1e-2a7b8c9d0e1f')
        ->notification_id->toBe('notification-id');
});
