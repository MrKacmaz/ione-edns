<?php

namespace App\Http\Requests;

use App\Data\NewNotificationData;
use App\Http\Requests\Concerns\HasNotificationRules;
use App\Services\Delivery\ChannelRegistry;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreNotificationRequest extends FormRequest
{
    use HasNotificationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @param  int  $maxScheduleDays  How many days ahead a notification may be scheduled.
     * @return array<string, list<ValidationRule|string|object>>
     */
    public function rules(
        ChannelRegistry $channels,
        #[Config('notifications.schedule.max_days')] int $maxScheduleDays,
    ): array {
        return $this->notificationRules($channels, $maxScheduleDays);
    }

    /**
     * Get the validated notification to create.
     */
    public function newNotification(): NewNotificationData
    {
        return NewNotificationData::fromValidated($this->validated());
    }
}
