<?php

namespace App\Notifications;

use App\Enums\CircuitState;
use App\Enums\NotificationChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells operators that a channel's deliveries paused or resumed.
 *
 * Queued, so a slow mail server never holds up the delivery worker that
 * noticed the change.
 */
class DeliveryCircuitChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly NotificationChannel $channel,
        public readonly CircuitState $state,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $replace = [
            'channel' => $this->channel->value,
            'rate' => config('notifications.circuit_breaker.failure_rate_percent'),
            'minimum' => config('notifications.circuit_breaker.minimum_attempts'),
            'window' => config('notifications.circuit_breaker.window_seconds'),
            'cooldown' => config('notifications.circuit_breaker.cooldown_seconds'),
        ];

        if ($this->state === CircuitState::Open) {
            return (new MailMessage)
                ->error()
                ->subject(__('alerts.circuit_opened.subject', $replace))
                ->line(__('alerts.circuit_opened.reason', $replace))
                ->line(__('alerts.circuit_opened.next', $replace));
        }

        return (new MailMessage)
            ->success()
            ->subject(__('alerts.circuit_closed.subject', $replace))
            ->line(__('alerts.circuit_closed.reason', $replace));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array{channel: string, state: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'channel' => $this->channel->value,
            'state' => $this->state->value,
        ];
    }
}
