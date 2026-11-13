<?php

namespace App\Rules;

use App\Enums\NotificationChannel;
use App\Models\NotificationTemplate;
use App\Rules\Concerns\ResolvesNotificationChannel;
use App\Services\Delivery\ChannelRegistry;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates the content of a template: its placeholders must be well formed, and
 * its fixed text, which every rendering contains, must fit the channel's limit.
 *
 * The channel is read from the data under validation unless a fixed channel is
 * given, as for an existing template whose channel cannot change.
 */
class NotificationTemplateContentRule implements DataAwareRule, ValidationRule
{
    use ResolvesNotificationChannel;

    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly ?NotificationChannel $channel = null,
    ) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $template = new NotificationTemplate(['content' => $value]);

        if ($template->hasMalformedPlaceholders()) {
            $fail('validation.notification_template_placeholders')->translate();

            return;
        }

        $deliveryChannel = $this->channel === null
            ? $this->deliveryChannelOf($attribute)
            : $this->channels->for($this->channel);

        // An unknown channel is already reported by the channel's own rules.
        if ($deliveryChannel === null) {
            return;
        }

        $maxLength = $deliveryChannel->maxContentLength();

        if ($template->fixedTextLength() > $maxLength) {
            $fail('validation.notification_template_content_length')->translate([
                'max' => $maxLength,
                'channel' => $deliveryChannel->channel()->value,
            ]);
        }
    }
}
