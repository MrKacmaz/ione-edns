<?php

namespace App\Rules;

use App\Rules\Concerns\ResolvesNotificationChannel;
use App\Services\Delivery\ChannelRegistry;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates that a recipient is addressable on its notification's channel.
 */
class NotificationRecipientRule implements DataAwareRule, ValidationRule
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

        if (! $deliveryChannel->isValidRecipient($value)) {
            $fail('validation.notification_recipient')->translate([
                'format' => $deliveryChannel->recipientFormat(),
                'channel' => $deliveryChannel->channel()->value,
            ]);
        }
    }
}
