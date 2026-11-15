<?php

use App\Models\Notification;
use App\Models\NotificationBatch;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

describe('statusCounts', function () {
    test('counts only the batch\'s notifications per status and lists statuses without notifications as zero', function () {
        $batch = NotificationBatch::factory()->create();
        Notification::factory()->inBatch($batch)->count(2)->create();
        Notification::factory()->inBatch($batch)->queued()->create();
        Notification::factory()->inBatch($batch)->sent()->create();
        Notification::factory()->inBatch(NotificationBatch::factory()->for($batch->user)->create())->failed()->create();
        Notification::factory()->for($batch->user)->cancelled()->create();

        $statusCounts = $batch->statusCounts();

        expect($statusCounts)->toBe([
            'waiting' => 2,
            'scheduled' => 0,
            'queued' => 1,
            'processing' => 0,
            'sent' => 1,
            'failed' => 0,
            'cancelled' => 0,
        ]);
    });
});

describe('cancelPendingNotifications', function () {
    test('cancels only the batch\'s notifications that no worker has picked up yet and returns how many', function () {
        $this->travelTo('2026-11-14 10:00:00.250000');
        $batch = NotificationBatch::factory()->create();
        $waiting = Notification::factory()->inBatch($batch)->create();
        $scheduled = Notification::factory()->inBatch($batch)->scheduled()->create();
        $queued = Notification::factory()->inBatch($batch)->queued()->create();
        $processing = Notification::factory()->inBatch($batch)->processing()->create();
        $sent = Notification::factory()->inBatch($batch)->sent()->create();
        $failed = Notification::factory()->inBatch($batch)->failed()->create();
        $cancelledEarlier = Notification::factory()->inBatch($batch)->cancelled()->create(['cancelled_at' => '2026-11-14 09:00:00']);
        $inOtherBatch = Notification::factory()->inBatch(NotificationBatch::factory()->for($batch->user)->create())->create();

        $cancelledCount = $batch->cancelPendingNotifications();

        expect($cancelledCount)->toBe(3);
        foreach ([$waiting, $scheduled, $queued] as $pending) {
            $this->assertDatabaseHas('notifications', ['id' => $pending->id, 'status' => 'cancelled', 'cancelled_at' => '2026-11-14 10:00:00.25']);
        }
        $this->assertDatabaseHas('notifications', ['id' => $processing->id, 'status' => 'processing']);
        $this->assertDatabaseHas('notifications', ['id' => $sent->id, 'status' => 'sent']);
        $this->assertDatabaseHas('notifications', ['id' => $failed->id, 'status' => 'failed']);
        $this->assertDatabaseHas('notifications', ['id' => $cancelledEarlier->id, 'status' => 'cancelled', 'cancelled_at' => '2026-11-14 09:00:00']);
        $this->assertDatabaseHas('notifications', ['id' => $inOtherBatch->id, 'status' => 'waiting']);
    });

    test('locks the notifications in id order, so concurrent cancellations of the batch cannot deadlock', function () {
        $batch = NotificationBatch::factory()->create();
        Notification::factory()->inBatch($batch)->count(3)->create();
        $lockingQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$lockingQueries): void {
            if (str_ends_with($query->sql, 'for update')) {
                $lockingQueries[] = $query->sql;
            }
        });

        $batch->cancelPendingNotifications();

        expect($lockingQueries)->toHaveCount(1)
            ->and($lockingQueries[0])->toContain('order by "id" asc for update');
    });

    test('announces each notification it cancels and none it leaves alone', function () {
        $batch = NotificationBatch::factory()->create();
        $waiting = Notification::factory()->inBatch($batch)->create();
        $scheduled = Notification::factory()->inBatch($batch)->scheduled()->create();
        Notification::factory()->inBatch($batch)->processing()->create();
        Notification::factory()->inBatch($batch)->cancelled()->create();
        Queue::fake([BroadcastEvent::class]);

        $batch->cancelPendingNotifications();

        expect(announcedStatuses())->toEqualCanonicalizing([
            ['id' => $waiting->id, 'status' => 'cancelled'],
            ['id' => $scheduled->id, 'status' => 'cancelled'],
        ]);
    });
});
