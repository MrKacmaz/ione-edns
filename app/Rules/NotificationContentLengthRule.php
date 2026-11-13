<?php

namespace App\Rules;

use App\Rules\Concerns\ResolvesNotificationChannel;
use App\Services\Delivery\ChannelRegistry;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates that content fits the character limit of its notification's channel.
 */
class NotificationContentLengthRule implements DataAwareRule, ValidationRule
{
    use ResolvesNotificationChannel;

    public function __construct(private readonly ChannelRegistry $channels) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $deliveryChannel = $this->deliveryChannelOf($attribute);

        // An unknown channel or a non-string value is already reported by the field's other rules.
        if ($deliveryChannel === null || ! is_string($value)) {
            return;
        }

        $maxLength = $deliveryChannel->maxContentLength();

        if (mb_strlen($value) > $maxLength) {
            $fail('validation.notification_content_length')->translate([
                'max' => $maxLength,
                'channel' => $deliveryChannel->channel()->value,
            ]);
        }
    }
}
