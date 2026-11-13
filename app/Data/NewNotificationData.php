<?php

namespace App\Data;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;

/**
 * A validated request to create one notification.
 */
final readonly class NewNotificationData
{
    public function __construct(
        public NotificationChannel $channel,
        public string $recipient,
        public string $content,
        public NotificationPriority $priority = NotificationPriority::Normal,
        public ?string $idempotencyKey = null,
    ) {}

    /**
     * Build the notification from input that already passed validation.
     *
     * @param  array<array-key, mixed>  $attributes
     */
    public static function fromValidated(array $attributes): self
    {
        return new self(
            channel: NotificationChannel::from($attributes['channel']),
            recipient: $attributes['recipient'],
            content: $attributes['content'],
            priority: isset($attributes['priority'])
                ? NotificationPriority::from($attributes['priority'])
                : NotificationPriority::Normal,
            idempotencyKey: $attributes['idempotency_key'] ?? null,
        );
    }
}
