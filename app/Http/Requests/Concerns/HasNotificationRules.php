<?php

namespace App\Http\Requests\Concerns;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Rules\NotificationContentLengthRule;
use App\Rules\NotificationRecipientRule;
use App\Services\Delivery\ChannelRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait HasNotificationRules
{
    /**
     * Get the rules for the attributes of one notification.
     *
     * @return array<string, list<ValidationRule|string|object>>
     */
    protected function notificationRules(ChannelRegistry $channels, string $prefix = ''): array
    {
        return [
            $prefix.'channel' => ['required', Rule::enum(NotificationChannel::class)],
            $prefix.'recipient' => ['required', 'string', 'max:512', new NotificationRecipientRule($channels)],
            $prefix.'content' => ['required', 'string', new NotificationContentLengthRule($channels)],
            $prefix.'priority' => ['sometimes', Rule::enum(NotificationPriority::class)],
            $prefix.'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
