<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use Carbon\CarbonImmutable;
use Database\Factories\NotificationTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Reusable notification content with {{ variable }} placeholders.
 *
 * A placeholder holds one variable name made of letters, digits and
 * underscores, optionally padded with spaces. Values are inserted as they
 * are, because notification content is plain text.
 *
 * @property string $id
 * @property int $user_id
 * @property string $name
 * @property NotificationChannel $channel
 * @property string $content
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[DateFormat('Y-m-d H:i:s.u')]
#[Fillable(['name', 'channel', 'content'])]
class NotificationTemplate extends Model
{
    /** @use HasFactory<NotificationTemplateFactory> */
    use HasFactory, HasUuids;

    /**
     * The most characters a template's content may have, placeholders included.
     *
     * As many as the channel with the longest content (email) accepts. The channel
     * limit itself applies to the fixed text and to every rendered notification;
     * this bounds what is stored, as placeholders alone could otherwise be
     * repeated without end.
     */
    public const int MAX_CONTENT_LENGTH = 10_000;

    /**
     * Matches one placeholder and captures its variable name.
     */
    private const string PLACEHOLDER_PATTERN = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
        ];
    }

    /**
     * Get the user that owns the template.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the notifications created from the template.
     *
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'template_id');
    }

    /**
     * Get the names of the variables the content refers to, in order of first use.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $this->content, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Determine whether the content has braces that do not form a valid placeholder.
     */
    public function hasMalformedPlaceholders(): bool
    {
        $textOutsidePlaceholders = (string) preg_replace(self::PLACEHOLDER_PATTERN, '', $this->content);

        return str_contains($textOutsidePlaceholders, '{{') || str_contains($textOutsidePlaceholders, '}}');
    }

    /**
     * Get the length of the content without its placeholders, which every rendering has at least.
     */
    public function fixedTextLength(): int
    {
        return mb_strlen((string) preg_replace(self::PLACEHOLDER_PATTERN, '', $this->content));
    }

    /**
     * Get the variables of the content that are not given a text or number value.
     *
     * @param  array<array-key, mixed>  $variables
     * @return list<string>
     */
    public function missingVariables(array $variables): array
    {
        return array_values(array_filter(
            $this->variables(),
            fn (string $name): bool => ! $this->isRenderableValue($variables[$name] ?? null),
        ));
    }

    /**
     * Fill the placeholders of the content with the given variables.
     *
     * @param  array<array-key, mixed>  $variables
     *
     * @throws LogicException when a variable has no text or number value.
     */
    public function render(array $variables): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER_PATTERN, function (array $placeholder) use ($variables): string {
            $value = $variables[$placeholder[1]] ?? null;

            if (! $this->isRenderableValue($value)) {
                throw new LogicException(__('notifications.template_variable_missing', ['variable' => $placeholder[1]]));
            }

            return (string) $value;
        }, $this->content);
    }

    /**
     * Determine whether a value can be put into the content.
     *
     * @phpstan-assert-if-true string|int|float $value
     */
    private function isRenderableValue(mixed $value): bool
    {
        return is_string($value) || is_int($value) || is_float($value);
    }
}
