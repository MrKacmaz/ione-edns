<?php

namespace App\Http\Requests;

use App\Enums\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Rules\NotificationTemplateContentRule;
use App\Services\Delivery\ChannelRegistry;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNotificationTemplateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * The name is unique per user and may contain letters, digits, dashes and underscores.
     *
     * @return array<string, list<ValidationRule|string|object>>
     */
    public function rules(ChannelRegistry $channels, #[CurrentUser] User $user): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:100',
                'alpha_dash:ascii',
                Rule::unique(NotificationTemplate::class)->where('user_id', $user->id),
            ],
            'channel' => ['required', Rule::enum(NotificationChannel::class)],
            'content' => ['required', 'string', 'max:'.NotificationTemplate::MAX_CONTENT_LENGTH, new NotificationTemplateContentRule($channels)],
        ];
    }
}
