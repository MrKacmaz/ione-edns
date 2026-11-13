<?php

namespace App\Rules;

use App\Models\NotificationTemplate;
use App\Rules\Concerns\ResolvesNotificationChannel;
use App\Services\Delivery\ChannelRegistry;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Validates a notification that is created from a template, named in the
 * notification's `template` attribute.
 *
 * The template must belong to the user and match the notification's channel,
 * the notification's `variables` must give a value to every placeholder, and
 * the rendered content must fit the channel's limit.
 */
class NotificationTemplateRule implements DataAwareRule, ValidationRule
{
    use ResolvesNotificationChannel;

    /**
     * @param  Collection<string, NotificationTemplate>  $templates  The user's templates the request refers to, keyed by name.
     */
    public function __construct(
        private readonly ChannelRegistry $channels,
        private readonly Collection $templates,
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

        $template = $this->templates->get($value);

        if ($template === null) {
            $fail('validation.notification_template_not_found')->translate(['name' => $value]);

            return;
        }

        $deliveryChannel = $this->deliveryChannelOf($attribute);

        // An unknown channel is already reported by the channel's own rules.
        if ($deliveryChannel === null) {
            return;
        }

        if ($template->channel !== $deliveryChannel->channel()) {
            $fail('validation.notification_template_channel')->translate([
                'name' => $value,
                'template_channel' => $template->channel->value,
                'channel' => $deliveryChannel->channel()->value,
            ]);

            return;
        }

        $variablesAttribute = $this->siblingOf($attribute, 'variables');
        $variables = data_get($this->data, $variablesAttribute);
        $variables = is_array($variables) ? $variables : [];
        $missingVariables = $template->missingVariables($variables);

        if ($missingVariables !== []) {
            $fail($variablesAttribute, 'validation.notification_template_variables')->translate([
                'variables' => implode(', ', $missingVariables),
            ]);

            return;
        }

        $maxLength = $deliveryChannel->maxContentLength();

        if (mb_strlen($template->render($variables)) > $maxLength) {
            $fail('validation.notification_template_rendered_length')->translate([
                'max' => $maxLength,
                'channel' => $deliveryChannel->channel()->value,
            ]);
        }
    }
}
