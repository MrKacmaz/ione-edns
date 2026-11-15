<?php

namespace Database\Factories;

use App\Enums\GatewayOutcome;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationAttempt>
 */
class NotificationAttemptFactory extends Factory
{
    /**
     * Define the model's default state: an attempt the provider accepted.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_id' => Notification::factory()->sent(),
            'attempt' => 1,
            'outcome' => GatewayOutcome::Accepted,
            'http_status' => 202,
            'provider_message_id' => (string) Str::uuid(),
            'error' => null,
            'duration_ms' => fake()->numberBetween(50, 500),
            'created_at' => now(),
        ];
    }

    /**
     * Indicate that the attempt failed in a way a later attempt may fix.
     */
    public function retryableFailure(): static
    {
        return $this->state(fn (array $attributes) => [
            'outcome' => GatewayOutcome::RetryableFailure,
            'http_status' => 503,
            'provider_message_id' => null,
            'error' => 'The provider responded with HTTP 503.',
        ]);
    }

    /**
     * Indicate that the provider asked to slow down.
     */
    public function throttled(): static
    {
        return $this->state(fn (array $attributes) => [
            'outcome' => GatewayOutcome::Throttled,
            'http_status' => 429,
            'provider_message_id' => null,
            'error' => 'The provider responded with HTTP 429.',
        ]);
    }

    /**
     * Indicate that the provider rejected the notification for good.
     */
    public function permanentFailure(): static
    {
        return $this->state(fn (array $attributes) => [
            'outcome' => GatewayOutcome::PermanentFailure,
            'http_status' => 400,
            'provider_message_id' => null,
            'error' => 'The provider responded with HTTP 400.',
        ]);
    }
}
