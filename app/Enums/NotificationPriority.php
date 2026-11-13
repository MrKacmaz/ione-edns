<?php

namespace App\Enums;

enum NotificationPriority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    /**
     * Get the queue that carries notifications of this priority.
     *
     * The Horizon supervisors in config/horizon.php work these queues in priority order.
     */
    public function queue(): string
    {
        return 'notifications-'.$this->value;
    }
}
