<?php

namespace App\Http\Requests\Concerns;

use App\Data\NewNotificationData;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Rules\Iso8601DateRule;
use App\Rules\NotificationContentLengthRule;
use App\Rules\NotificationRecipientRule;
use App\Rules\NotificationTemplateRule;
use App\Services\Delivery\ChannelRegistry;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;

trait HasNotificationRules
{
    /**
     * The user's templates the request refers to, keyed by name, once loaded.
     *
     * @var Collection<string, NotificationTemplate>|null
     */
    private ?Collection $templatesByName = null;

    /**
     * Get the template names the request refers to, as sent.
     *
     * @return list<mixed>
     */
    abstract protected function templateNames(): array;

    /**
     * Get the rules for the attributes of one notification.
     *
     * A notification either carries its `content`, or names a `template` and gives
     * its placeholders a value in `variables`. A scheduled_at is an ISO 8601
     * date-time in the future, at most $maxScheduleDays ahead.
     *
     * @return array<string, list<ValidationRule|string|object>>
     */
    protected function notificationRules(ChannelRegistry $channels, int $maxScheduleDays, string $prefix = ''): array
    {
        return [
            $prefix.'channel' => ['required', Rule::enum(NotificationChannel::class)],
            $prefix.'recipient' => ['required', 'string', 'max:512', new NotificationRecipientRule($channels)],
            $prefix.'content' => [
                'required_without:'.$prefix.'template',
                'prohibits:'.$prefix.'template,'.$prefix.'variables',
                'string',
                new NotificationContentLengthRule($channels),
            ],
            $prefix.'template' => ['sometimes', 'string', 'max:100', new NotificationTemplateRule($channels, $this->templatesByName())],
            $prefix.'variables' => ['sometimes', 'array'],
            $prefix.'priority' => ['sometimes', Rule::enum(NotificationPriority::class)],
            $prefix.'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            $prefix.'scheduled_at' => [
                'sometimes',
                'nullable',
                'date',
                new Iso8601DateRule,
                'after:now',
                'before_or_equal:'.now()->addDays($maxScheduleDays)->toIso8601String(),
            ],
        ];
    }

    /**
     * Build the notification to create from its validated attributes, rendering its template if it names one.
     *
     * @param  array<array-key, mixed>  $attributes
     */
    protected function newNotificationFrom(array $attributes): NewNotificationData
    {
        $template = is_string($attributes['template'] ?? null)
            ? $this->templatesByName()->get($attributes['template'])
            : null;

        if ($template === null) {
            return NewNotificationData::fromValidated($attributes);
        }

        $variables = $attributes['variables'] ?? [];

        return NewNotificationData::fromValidated([
            ...$attributes,
            'content' => $template->render(is_array($variables) ? $variables : []),
            'template_id' => $template->id,
        ]);
    }

    /**
     * Get the user's templates the request refers to, keyed by name, loading them with one query.
     *
     * @return Collection<string, NotificationTemplate>
     */
    private function templatesByName(): Collection
    {
        if ($this->templatesByName !== null) {
            return $this->templatesByName;
        }

        $names = array_values(array_unique(array_filter($this->templateNames(), is_string(...))));
        $user = $this->user();

        if ($names === [] || ! $user instanceof User) {
            return $this->templatesByName = new Collection;
        }

        return $this->templatesByName = $user->notificationTemplates()
            ->whereIn('name', $names)
            ->get()
            ->keyBy('name');
    }
}
