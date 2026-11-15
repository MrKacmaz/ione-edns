<?php

namespace App\Http\Requests;

use App\Data\NewNotificationData;
use App\Http\Requests\Concerns\HasNotificationRules;
use App\Services\Delivery\ChannelRegistry;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

/**
 * The dashboard's send form: one notification, repeated `count` times.
 *
 * The notification is validated like one of the API's, except that the form
 * neither schedules notifications nor carries idempotency keys.
 */
class StoreDashboardNotificationsRequest extends FormRequest
{
    use HasNotificationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @param  int  $maxBatchSize  The most notifications one request may create, as for an API batch.
     * @param  int  $maxScheduleDays  How many days ahead a notification may be scheduled.
     * @return array<string, list<ValidationRule|string|object>>
     */
    public function rules(
        ChannelRegistry $channels,
        #[Config('notifications.batch.max_size')] int $maxBatchSize,
        #[Config('notifications.schedule.max_days')] int $maxScheduleDays,
    ): array {
        return [
            ...Arr::except($this->notificationRules($channels, $maxScheduleDays), ['idempotency_key', 'scheduled_at']),
            'count' => ['required', 'integer', 'min:1', 'max:'.$maxBatchSize],
        ];
    }

    /**
     * Get the validated notification, once per requested copy.
     *
     * @return list<NewNotificationData>
     */
    public function newNotifications(): array
    {
        $newNotification = $this->newNotificationFrom(Arr::except($this->validated(), ['count']));

        return array_fill(0, $this->integer('count'), $newNotification);
    }

    /**
     * Get the template names the request refers to, as sent.
     *
     * @return list<mixed>
     */
    protected function templateNames(): array
    {
        return [$this->input('template')];
    }
}
