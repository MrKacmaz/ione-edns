<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A notification reached a final status: sent, failed or cancelled.
 *
 * Broadcast on the owner's private channel, so API clients and the dashboard
 * learn the outcome without polling. Intermediate statuses are not broadcast,
 * because they would multiply the events per notification.
 *
 * The payload is captured when the event is created, so the queued broadcast
 * needs no database read; the content is left out to keep messages small.
 */
class NotificationStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * The queue the broadcast is sent from, apart from the delivery queues.
     */
    public string $queue = 'broadcasts';

    public readonly int $userId;

    /**
     * @var array<string, mixed>
     */
    public readonly array $notification;

    public function __construct(Notification $notification)
    {
        $this->userId = $notification->user_id;
        $this->notification = [
            'id' => $notification->id,
            'batch_id' => $notification->batch_id,
            'channel' => $notification->channel->value,
            'priority' => $notification->priority->value,
            'status' => $notification->status->value,
            'recipient' => $notification->recipient,
            'attempts' => $notification->attempts,
            'provider_message_id' => $notification->provider_message_id,
            'last_error' => $notification->last_error,
            'sent_at' => $notification->sent_at?->toJSON(),
            'failed_at' => $notification->failed_at?->toJSON(),
            'cancelled_at' => $notification->cancelled_at?->toJSON(),
            'updated_at' => $notification->updated_at->toJSON(),
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    /**
     * Get the event name clients listen for.
     */
    public function broadcastAs(): string
    {
        return 'notification.status-changed';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array{notification: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return ['notification' => $this->notification];
    }
}
