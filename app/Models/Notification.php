<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $batch_id
 * @property NotificationChannel $channel
 * @property NotificationPriority $priority
 * @property NotificationStatus $status
 * @property string $recipient
 * @property string $content
 * @property string|null $idempotency_key
 * @property string|null $correlation_id
 * @property int $attempts
 * @property string|null $provider_message_id
 * @property string|null $last_error
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $queued_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[DateFormat('Y-m-d H:i:s.u')]
#[Fillable([
    'channel',
    'priority',
    'status',
    'recipient',
    'content',
    'idempotency_key',
    'correlation_id',
    'scheduled_at',
])]
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'priority' => NotificationPriority::class,
            'status' => NotificationStatus::class,
            'attempts' => 'integer',
            'scheduled_at' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns the notification.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the batch the notification was created in.
     *
     * @return BelongsTo<NotificationBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(NotificationBatch::class, 'batch_id');
    }

    /**
     * Get every provider call made for the notification.
     *
     * @return HasMany<NotificationAttempt, $this>
     */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(NotificationAttempt::class);
    }

    /**
     * Claim the notification for one delivery attempt.
     *
     * The status check, the claim and the attempt count happen in one statement,
     * so two workers holding a job for the same notification never both deliver it,
     * and a notification cancelled in the meantime is left alone.
     */
    public static function claimForDelivery(string $id): ?self
    {
        $claimedRows = static::query()
            ->whereKey($id)
            ->whereIn('status', NotificationStatus::claimable())
            ->increment('attempts', 1, ['status' => NotificationStatus::Processing]);

        return $claimedRows === 1 ? static::query()->find($id) : null;
    }

    /**
     * Record that the provider accepted the notification.
     */
    public function markSent(?string $providerMessageId): void
    {
        $this->transitionFromProcessing([
            'status' => NotificationStatus::Sent,
            'provider_message_id' => $providerMessageId,
            'last_error' => null,
            'sent_at' => now(),
        ]);
    }

    /**
     * Put the notification back in line for another attempt.
     */
    public function markRetryPending(string $error): void
    {
        $this->transitionFromProcessing([
            'status' => NotificationStatus::Queued,
            'last_error' => $error,
        ]);
    }

    /**
     * Record that the notification failed for good.
     */
    public function markFailed(string $error): void
    {
        $this->transitionFromProcessing([
            'status' => NotificationStatus::Failed,
            'last_error' => $error,
            'failed_at' => now(),
        ]);
    }

    /**
     * Fail a notification whose delivery job died while the notification was being processed.
     */
    public static function markFailedIfStillProcessing(string $id, string $error): void
    {
        static::query()
            ->whereKey($id)
            ->where('status', NotificationStatus::Processing)
            ->update([
                'status' => NotificationStatus::Failed,
                'last_error' => $error,
                'failed_at' => now(),
            ]);
    }

    /**
     * Give a failed notification a fresh set of delivery attempts.
     *
     * Earlier attempts stay in the delivery history.
     */
    public function resetForRedelivery(): bool
    {
        $resetRows = static::query()
            ->whereKey($this->getKey())
            ->where('status', NotificationStatus::Failed)
            ->update([
                'status' => NotificationStatus::Waiting,
                'attempts' => 0,
                'last_error' => null,
                'failed_at' => null,
            ]);

        $this->refresh();

        return $resetRows === 1;
    }

    /**
     * Cancel the notification if it has not been picked up by a worker yet.
     *
     * The status check and the update run as one statement, so a worker that
     * claims the notification at the same moment cannot also deliver it.
     */
    public function cancel(): bool
    {
        $cancelledRows = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', NotificationStatus::cancellable())
            ->update([
                'status' => NotificationStatus::Cancelled,
                'cancelled_at' => now(),
            ]);

        $this->refresh();

        return $cancelledRows === 1;
    }

    /**
     * Apply a status change only while the notification is still being processed.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function transitionFromProcessing(array $attributes): void
    {
        static::query()
            ->whereKey($this->getKey())
            ->where('status', NotificationStatus::Processing)
            ->update($attributes);

        $this->refresh();
    }
}
