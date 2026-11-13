<?php

namespace App\Actions;

use App\Data\NewNotificationData;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

class CreateNotificationsAction
{
    /**
     * The number of rows written per insert statement.
     */
    private const int INSERT_CHUNK_SIZE = 500;

    public function __construct(private readonly DispatchNotificationsAction $dispatchNotifications) {}

    /**
     * Persist the notifications for the user in a single transaction and queue the new ones.
     *
     * A notification whose idempotency key the user already used resolves to the
     * existing notification instead of creating a duplicate. The result follows
     * the order of the given notifications, and only the notifications created by
     * this call are flagged with `wasRecentlyCreated`.
     *
     * @param  list<NewNotificationData>  $newNotifications
     * @return Collection<int, Notification>
     */
    public function handle(User $user, array $newNotifications, ?NotificationBatch $batch = null): Collection
    {
        return DB::transaction(function () use ($user, $newNotifications, $batch): Collection {
            $existingByKey = $this->findByIdempotencyKeys($user, $this->idempotencyKeysOf($newNotifications));

            $rows = [];
            $newIdsByPosition = [];
            $timestamp = now()->format('Y-m-d H:i:s.u');
            $correlationId = Context::get('correlation_id');

            foreach ($newNotifications as $position => $newNotification) {
                if ($newNotification->idempotencyKey !== null && $existingByKey->has($newNotification->idempotencyKey)) {
                    continue;
                }

                $newIdsByPosition[$position] = (string) Str::uuid7();

                $rows[] = [
                    'id' => $newIdsByPosition[$position],
                    'user_id' => $user->id,
                    'batch_id' => $batch?->id,
                    'channel' => $newNotification->channel->value,
                    'priority' => $newNotification->priority->value,
                    'status' => $newNotification->isScheduled()
                        ? NotificationStatus::Scheduled->value
                        : NotificationStatus::Waiting->value,
                    'recipient' => $newNotification->recipient,
                    'content' => $newNotification->content,
                    'idempotency_key' => $newNotification->idempotencyKey,
                    'correlation_id' => is_string($correlationId) ? $correlationId : null,
                    'scheduled_at' => $newNotification->scheduledAt?->format('Y-m-d H:i:s.u'),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            // A concurrent request may insert the same idempotency key first; those rows are skipped here
            // and resolved to the concurrent request's notification below.
            foreach (array_chunk($rows, self::INSERT_CHUNK_SIZE) as $chunk) {
                Notification::query()->insertOrIgnore($chunk);
            }

            $createdById = Notification::query()->whereKey(array_values($newIdsByPosition))->get()->keyBy('id');
            $insertedConcurrentlyByKey = $this->findByIdempotencyKeys(
                $user,
                $this->idempotencyKeysOf(array_filter(
                    $newNotifications,
                    fn (NewNotificationData $newNotification, int $position): bool => ! $createdById->has($newIdsByPosition[$position] ?? ''),
                    ARRAY_FILTER_USE_BOTH,
                )),
            );

            $notifications = new Collection;

            foreach ($newNotifications as $position => $newNotification) {
                $created = $createdById->get($newIdsByPosition[$position] ?? '');

                if ($created !== null) {
                    $created->wasRecentlyCreated = true;
                    $notifications->push($created);

                    continue;
                }

                $notifications->push(
                    $existingByKey->get((string) $newNotification->idempotencyKey)
                        ?? $insertedConcurrentlyByKey->get((string) $newNotification->idempotencyKey)
                        ?? throw new LogicException(__('notifications.not_created_nor_found', ['position' => $position])),
                );
            }

            // Queue the new notifications only once the outermost transaction has committed,
            // so a worker never looks for a notification that is not visible yet. Scheduled
            // notifications further ahead are left to notifications:dispatch-due.
            DB::afterCommit(fn () => $this->dispatchNotifications->handle(
                $notifications->filter(
                    fn (Notification $notification): bool => $notification->wasRecentlyCreated && $notification->isDueForDispatch(),
                )->values(),
            ));

            return $notifications;
        });
    }

    /**
     * Create a batch for the user together with its notifications.
     *
     * @param  list<NewNotificationData>  $newNotifications
     * @return array{batch: NotificationBatch, notifications: Collection<int, Notification>}
     */
    public function handleBatch(User $user, array $newNotifications): array
    {
        return DB::transaction(function () use ($user, $newNotifications): array {
            $batch = $user->notificationBatches()->create();

            return [
                'batch' => $batch,
                'notifications' => $this->handle($user, $newNotifications, $batch),
            ];
        });
    }

    /**
     * Find the user's notifications with the given idempotency keys.
     *
     * @param  list<string>  $idempotencyKeys
     * @return Collection<string, Notification>
     */
    private function findByIdempotencyKeys(User $user, array $idempotencyKeys): Collection
    {
        if ($idempotencyKeys === []) {
            return new Collection;
        }

        return $user->notifications()
            ->whereIn('idempotency_key', $idempotencyKeys)
            ->get()
            ->keyBy(fn (Notification $notification): string => (string) $notification->idempotency_key);
    }

    /**
     * Get the idempotency keys carried by the given notifications.
     *
     * @param  array<int, NewNotificationData>  $newNotifications
     * @return list<string>
     */
    private function idempotencyKeysOf(array $newNotifications): array
    {
        $idempotencyKeys = [];

        foreach ($newNotifications as $newNotification) {
            if ($newNotification->idempotencyKey !== null) {
                $idempotencyKeys[] = $newNotification->idempotencyKey;
            }
        }

        return $idempotencyKeys;
    }
}
