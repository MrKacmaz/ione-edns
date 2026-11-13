<?php

namespace App\Http\Requests;

use App\Models\NotificationTemplate;
use App\Rules\NotificationTemplateContentRule;
use App\Services\Delivery\ChannelRegistry;
use Illuminate\Container\Attributes\RouteParameter;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationTemplateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Only the given attributes change. The channel is fixed once the template
     * exists; create a new template for another channel.
     *
     * @return array<string, list<ValidationRule|string|object>>
     */
    public function rules(ChannelRegistry $channels, #[RouteParameter('template')] NotificationTemplate $template): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                'alpha_dash:ascii',
                Rule::unique(NotificationTemplate::class)->where('user_id', $template->user_id)->ignore($template),
            ],
            'channel' => ['prohibited'],
            'content' => ['sometimes', 'string', new NotificationTemplateContentRule($channels, $template->channel)],
        ];
    }
}
