<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;

test('routes each channel and priority to its own notifications queue', function (NotificationPriority $priority, NotificationChannel $channel, string $expectedQueue) {
    expect($priority->queueFor($channel))->toBe($expectedQueue);
})->with([
    'sms, high' => [NotificationPriority::High, NotificationChannel::Sms, 'notifications-sms-high'],
    'email, normal' => [NotificationPriority::Normal, NotificationChannel::Email, 'notifications-email-normal'],
    'push, low' => [NotificationPriority::Low, NotificationChannel::Push, 'notifications-push-low'],
]);

test('lists a channel\'s queues highest priority first', function () {
    expect(NotificationPriority::queuesFor(NotificationChannel::Email))
        ->toBe(['notifications-email-high', 'notifications-email-normal', 'notifications-email-low']);
});
