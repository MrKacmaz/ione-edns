<?php

use App\Enums\NotificationPriority;

test('routes each priority to its own notifications queue', function (NotificationPriority $priority, string $expectedQueue) {
    expect($priority->queue())->toBe($expectedQueue);
})->with([
    'high' => [NotificationPriority::High, 'notifications-high'],
    'normal' => [NotificationPriority::Normal, 'notifications-normal'],
    'low' => [NotificationPriority::Low, 'notifications-low'],
]);
