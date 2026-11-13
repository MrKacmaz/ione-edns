<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'channel' => NotificationChannel::Sms,
            'priority' => NotificationPriority::Normal,
            'status' => NotificationStatus::Waiting,
            'recipient' => '+90555'.fake()->numerify('#######'),
            'content' => fake()->sentence(),
        ];
    }

    /**
     * Indicate that the notification is delivered by email.
     */
    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => NotificationChannel::Email,
            'recipient' => fake()->safeEmail(),
            'content' => fake()->paragraph(),
        ]);
    }

    /**
     * Indicate that the notification is delivered as a push message.
     */
    public function push(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => NotificationChannel::Push,
            'recipient' => Str::random(64),
        ]);
    }

    /**
     * Indicate that the notification belongs to the given batch and its owner.
     */
    public function inBatch(NotificationBatch $batch): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $batch->user_id,
            'batch_id' => $batch->id,
        ]);
    }

    /**
     * Indicate that the notification carries a client supplied idempotency key.
     */
    public function withIdempotencyKey(?string $idempotencyKey = null): static
    {
        return $this->state(fn (array $attributes) => [
            'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
        ]);
    }

    /**
     * Indicate that the notification has been pushed onto a queue.
     */
    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => NotificationStatus::Queued,
            'queued_at' => now(),
        ]);
    }

    /**
     * Indicate that a worker is delivering the notification.
     */
    public function processing(): static
    {
        return $this->queued()->state(fn (array $attributes) => [
            'status' => NotificationStatus::Processing,
            'attempts' => 1,
        ]);
    }

    /**
     * Indicate that the provider accepted the notification.
     */
    public function sent(): static
    {
        return $this->queued()->state(fn (array $attributes) => [
            'status' => NotificationStatus::Sent,
            'attempts' => 1,
            'provider_message_id' => (string) Str::uuid(),
            'sent_at' => now(),
        ]);
    }

    /**
     * Indicate that the notification permanently failed.
     */
    public function failed(): static
    {
        return $this->queued()->state(fn (array $attributes) => [
            'status' => NotificationStatus::Failed,
            'attempts' => 5,
            'last_error' => 'Provider responded with HTTP 503.',
            'failed_at' => now(),
        ]);
    }

    /**
     * Indicate that the notification was cancelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => NotificationStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Indicate that the notification is held back until a future delivery time.
     */
    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => NotificationStatus::Scheduled,
            'scheduled_at' => now()->addHour(),
        ]);
    }
}
