<?php

use App\Data\NewNotificationData;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;

test('builds an immediate normal priority notification when the optional attributes are missing', function () {
    $notification = NewNotificationData::fromValidated([
        'channel' => 'sms',
        'recipient' => '+905551234567',
        'content' => 'Your order has shipped.',
    ]);

    expect($notification)
        ->channel->toBe(NotificationChannel::Sms)
        ->recipient->toBe('+905551234567')
        ->content->toBe('Your order has shipped.')
        ->priority->toBe(NotificationPriority::Normal)
        ->idempotencyKey->toBeNull()
        ->scheduledAt->toBeNull()
        ->templateId->toBeNull()
        ->isScheduled()->toBeFalse();
});

test('treats a null idempotency key and scheduled time as missing', function () {
    $notification = NewNotificationData::fromValidated([
        'channel' => 'email',
        'recipient' => 'customer@example.com',
        'content' => 'Your invoice is ready.',
        'idempotency_key' => null,
        'scheduled_at' => null,
    ]);

    expect($notification)
        ->idempotencyKey->toBeNull()
        ->scheduledAt->toBeNull()
        ->isScheduled()->toBeFalse();
});

test('uses the requested priority', function (string $priority, NotificationPriority $expectedPriority) {
    $notification = NewNotificationData::fromValidated([
        'channel' => 'push',
        'recipient' => 'device-token-123',
        'content' => 'Flash sale starts now.',
        'priority' => $priority,
    ]);

    expect($notification->priority)->toBe($expectedPriority);
})->with([
    'high' => ['high', NotificationPriority::High],
    'low' => ['low', NotificationPriority::Low],
]);

test('keeps the idempotency key and the template of the request', function () {
    $notification = NewNotificationData::fromValidated([
        'channel' => 'sms',
        'recipient' => '+905551234567',
        'content' => 'Hello Ada, your code is 1234.',
        'idempotency_key' => 'order-42-shipped',
        'template_id' => '019a8f5e-2c4b-7d1e-9f3a-6b5c4d3e2f1a',
    ]);

    expect($notification)
        ->idempotencyKey->toBe('order-42-shipped')
        ->templateId->toBe('019a8f5e-2c4b-7d1e-9f3a-6b5c4d3e2f1a');
});

test('schedules the notification at the requested time converted to UTC', function () {
    $notification = NewNotificationData::fromValidated([
        'channel' => 'sms',
        'recipient' => '+905551234567',
        'content' => 'Your appointment is tomorrow.',
        'scheduled_at' => '2026-12-01T10:30:00+03:00',
    ]);

    expect($notification)
        ->scheduledAt->toIso8601String()->toBe('2026-12-01T07:30:00+00:00')
        ->isScheduled()->toBeTrue();
});
