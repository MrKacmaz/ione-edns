<?php

use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use Illuminate\Support\Facades\Queue;

test('queues scheduled notifications that come due before the next run', function () {
    Queue::fake([SendNotificationJob::class]);
    $dueSoon = Notification::factory()->scheduled()->create(['scheduled_at' => now()->addSeconds(45)]);
    $overdue = Notification::factory()->scheduled()->create(['scheduled_at' => now()->subMinutes(5)]);

    $this->artisan('notifications:dispatch-due')->assertSuccessful();

    Queue::assertPushed(SendNotificationJob::class, 2);
    expect($dueSoon->refresh()->status)->toBe(NotificationStatus::Queued)
        ->and($overdue->refresh()->status)->toBe(NotificationStatus::Queued);
});

test('picks up notifications due within the next sixty seconds and no later', function () {
    Queue::fake([SendNotificationJob::class]);
    $this->travelTo('2026-11-14T12:00:00Z');
    $dueAtLookaheadEnd = Notification::factory()->scheduled()->create(['scheduled_at' => now()->addSeconds(60)]);
    $dueJustAfter = Notification::factory()->scheduled()->create(['scheduled_at' => now()->addSeconds(61)]);

    $this->artisan('notifications:dispatch-due')->assertSuccessful();

    expect(Queue::pushed(SendNotificationJob::class)->map(fn (SendNotificationJob $job): string => $job->notificationId)->values()->all())
        ->toBe([$dueAtLookaheadEnd->id]);
    expect($dueJustAfter->refresh()->status)->toBe(NotificationStatus::Scheduled);
});

test('reports how many notifications it dispatched', function () {
    Queue::fake([SendNotificationJob::class]);
    Notification::factory()->scheduled()->count(2)->create(['scheduled_at' => now()->subMinute()]);

    $this->artisan('notifications:dispatch-due')
        ->expectsOutput('Dispatched 2 due scheduled notifications.')
        ->assertSuccessful();
});
