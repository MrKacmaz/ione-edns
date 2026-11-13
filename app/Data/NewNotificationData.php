<?php

namespace App\Data;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use Carbon\CarbonImmutable;

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
        public ?CarbonImmutable $scheduledAt = null,
        public ?string $templateId = null,
    ) {}

    /**
     * Build the notification from input that already passed validation.
     *
     * For a notification created from a template, `content` holds the rendered
     * content and `template_id` the template.
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
            scheduledAt: isset($attributes['scheduled_at'])
                ? CarbonImmutable::parse($attributes['scheduled_at'])->utc()
                : null,
            templateId: $attributes['template_id'] ?? null,
        );
    }

    /**
     * Determine whether the notification is to be sent at a later time.
     */
    public function isScheduled(): bool
    {
        return $this->scheduledAt !== null;
    }
}
