<?php

namespace App\Http\Requests;

use App\Data\NewNotificationData;
use App\Http\Requests\Concerns\HasNotificationRules;
use App\Services\Delivery\ChannelRegistry;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreNotificationBatchRequest extends FormRequest
{
    use HasNotificationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * A single invalid notification rejects the whole batch.
     *
     * @param  int  $maxBatchSize  The maximum number of notifications a batch may contain.
     * @param  int  $maxScheduleDays  How many days ahead a notification may be scheduled.
     * @return array<string, list<ValidationRule|string|object>>
     */
    public function rules(
        ChannelRegistry $channels,
        #[Config('notifications.batch.max_size')] int $maxBatchSize,
        #[Config('notifications.schedule.max_days')] int $maxScheduleDays,
    ): array {
        return [
            'notifications' => ['required', 'array', 'list', 'min:1', 'max:'.$maxBatchSize],
            'notifications.*' => ['required', 'array'],
            ...$this->notificationRules($channels, $maxScheduleDays, 'notifications.*.'),
            'notifications.*.idempotency_key' => ['sometimes', 'nullable', 'string', 'max:255', 'distinct'],
        ];
    }

    /**
     * Get the validated notifications to create, in request order.
     *
     * @return list<NewNotificationData>
     */
    public function newNotifications(): array
    {
        return array_values(array_map(
            $this->newNotificationFrom(...),
            $this->validated('notifications'),
        ));
    }

    /**
     * Get the template names the request refers to, as sent.
     *
     * @return list<mixed>
     */
    protected function templateNames(): array
    {
        $notifications = $this->input('notifications');

        return is_array($notifications)
            ? array_column(array_filter($notifications, is_array(...)), 'template')
            : [];
    }
}
