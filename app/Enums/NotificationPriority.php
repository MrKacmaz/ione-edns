<?php

namespace App\Enums;

enum NotificationPriority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    /**
     * Get the queue that carries notifications of this priority on the given channel.
     *
     * Every channel has its own queues, so a channel that is waiting for send slots
     * holds up only its own workers. The Horizon supervisors in config/horizon.php
     * work each channel's queues in priority order.
     */
    public function queueFor(NotificationChannel $channel): string
    {
        return 'notifications-'.$channel->value.'-'.$this->value;
    }

    /**
     * Get a channel's queues, highest priority first.
     *
     * @return list<string>
     */
    public static function queuesFor(NotificationChannel $channel): array
    {
        return array_map(fn (self $priority): string => $priority->queueFor($channel), self::cases());
    }
}
