<?php

namespace App\Events;

use App\Enums\CircuitState;
use App\Enums\NotificationChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A channel's delivery circuit opened or closed.
 *
 * Broadcast to the operators at once, rather than with the next metrics snapshot.
 */
class CircuitStateChanged implements ShouldBroadcast
{
    use Dispatchable;

    /**
     * The queue the broadcast is sent from, apart from the delivery queues.
     */
    public string $queue = 'broadcasts';

    public function __construct(
        public readonly NotificationChannel $channel,
        public readonly CircuitState $state,
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('operators')];
    }

    /**
     * Get the event name clients listen for.
     */
    public function broadcastAs(): string
    {
        return 'circuit.changed';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array{channel: string, state: string}
     */
    public function broadcastWith(): array
    {
        return [
            'channel' => $this->channel->value,
            'state' => $this->state->value,
        ];
    }
}
