<?php

namespace App\Models;

use App\Enums\NotificationStatus;
use App\Events\NotificationStatusChanged;
use Carbon\CarbonImmutable;
use Database\Factories\NotificationBatchFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property string $id
 * @property int $user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[DateFormat('Y-m-d H:i:s.u')]
class NotificationBatch extends Model
{
    /** @use HasFactory<NotificationBatchFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the user that owns the batch.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the notifications created in the batch.
     *
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'batch_id');
    }

    /**
     * Count the batch's notifications per status, including statuses with no notifications.
     *
     * @return array<value-of<NotificationStatus>, int>
     */
    public function statusCounts(): array
    {
        $countsByStatus = $this->notifications()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $statusCounts = [];

        foreach (NotificationStatus::cases() as $status) {
            $statusCounts[$status->value] = (int) $countsByStatus->get($status->value, 0);
        }

        return $statusCounts;
    }

    /**
     * Cancel every notification in the batch that has not been picked up by a worker yet.
     *
     * The cancellable notifications are locked while they are cancelled, so a
     * worker that tries to claim one at the same moment waits and then finds it
     * cancelled. Each cancellation is announced to the owner once committed.
     *
     * @return int The number of notifications that were cancelled.
     */
    public function cancelPendingNotifications(): int
    {
        return DB::transaction(function (): int {
            $notifications = $this->notifications()
                ->whereIn('status', NotificationStatus::cancellable())
                ->lockForUpdate()
                ->get();

            if ($notifications->isEmpty()) {
                return 0;
            }

            $cancelledAt = now();

            $cancellation = [
                'status' => NotificationStatus::Cancelled,
                'cancelled_at' => $cancelledAt,
                'updated_at' => $cancelledAt,
            ];

            Notification::query()->whereKey($notifications->modelKeys())->update($cancellation);

            foreach ($notifications as $notification) {
                $notification->forceFill($cancellation)->syncOriginal();

                NotificationStatusChanged::dispatch($notification);
            }

            return $notifications->count();
        });
    }
}
