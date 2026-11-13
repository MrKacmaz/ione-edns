<?php

namespace App\Http\Requests;

use App\Channels\ChannelRegistry;
use App\Data\NewNotificationData;
use App\Http\Requests\Concerns\HasNotificationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreNotificationRequest extends FormRequest
{
    use HasNotificationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, list<ValidationRule|string|object>>
     */
    public function rules(ChannelRegistry $channels): array
    {
        return $this->notificationRules($channels);
    }

    /**
     * Get the validated notification to create.
     */
    public function newNotification(): NewNotificationData
    {
        return NewNotificationData::fromValidated($this->validated());
    }
}
