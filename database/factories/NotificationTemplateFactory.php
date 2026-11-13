<?php

namespace Database\Factories;

use App\Enums\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
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
            'name' => fake()->unique()->slug(2),
            'channel' => NotificationChannel::Sms,
            'content' => 'Hi {{ name }}, your order {{ order_id }} has shipped.',
        ];
    }

    /**
     * Indicate that the template is for email notifications.
     */
    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => NotificationChannel::Email,
            'content' => "Hello {{ name }},\n\nYour invoice {{ invoice_id }} is ready.",
        ]);
    }
}
