<?php

namespace App\Models;

use App\Enums\GatewayOutcome;
use Carbon\CarbonImmutable;
use Database\Factories\NotificationAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call to the provider for a notification, kept as delivery history.
 *
 * @property int $id
 * @property string $notification_id
 * @property int $attempt
 * @property GatewayOutcome $outcome
 * @property int|null $http_status
 * @property string|null $provider_message_id
 * @property string|null $error
 * @property int $duration_ms
 * @property CarbonImmutable $created_at
 */
#[DateFormat('Y-m-d H:i:s.u')]
#[Fillable([
    'attempt',
    'outcome',
    'http_status',
    'provider_message_id',
    'error',
    'duration_ms',
])]
class NotificationAttempt extends Model
{
    /** @use HasFactory<NotificationAttemptFactory> */
    use HasFactory;

    /**
     * Attempts are never updated after they are recorded.
     */
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => GatewayOutcome::class,
            'attempt' => 'integer',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /**
     * Get the notification the attempt belongs to.
     *
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
