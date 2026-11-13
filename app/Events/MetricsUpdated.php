<?php

namespace App\Events;

use App\Data\MetricsSnapshotData;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A fresh metrics snapshot for the operator dashboard.
 *
 * Sent straight from the scheduler process that collected it, without a queue.
 */
class MetricsUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public readonly MetricsSnapshotData $snapshot) {}

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
        return 'metrics.updated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array{metrics: array<string, mixed>}
     */
    public function broadcastWith(): array
    {
        return ['metrics' => $this->snapshot->toArray()];
    }
}
