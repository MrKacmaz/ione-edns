<?php

use App\Actions\DispatchNotificationsAction;
use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use App\Models\Notification;
use App\Services\Delivery\RateLimiting\RedisGcraRateLimiter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

/*
 * Real jobs on a real Redis queue, worked by a real queue worker: what Queue::fake
 * cannot show. The worker drains the queues it is given in the order given, as
 * Horizon's supervisors do with `balance: false` (see config/horizon.php).
 */

/**
 * Delete the delivery and broadcast queues of the notification channels from the test Redis database.
 */
function clearDeliveryQueues(): void
{
    $redis = Redis::connection();

    foreach ([...NotificationPriority::queuesFor(NotificationChannel::Sms), ...NotificationPriority::queuesFor(NotificationChannel::Email), 'broadcasts'] as $queue) {
        $redis->del("queues:{$queue}", "queues:{$queue}:delayed", "queues:{$queue}:reserved", "queues:{$queue}:notify");
    }

    foreach (NotificationChannel::cases() as $channel) {
        $redis->del(RedisGcraRateLimiter::key($channel));
    }
}

/**
 * Work the given queues, in the given order, until they are empty.
 *
 * @param  list<string>  $queues
 */
function workQueues(array $queues): void
{
    Artisan::call('queue:work', [
        'connection' => 'redis',
        '--queue' => implode(',', $queues),
        '--stop-when-empty' => true,
        '--sleep' => 0,
        // The worker stops once the process uses more memory than this, and the test process
        // already holds the whole suite; the default of 128 MB would stop it after one job.
        '--memory' => 4096,
    ]);
}

beforeEach(function () {
    config(['queue.default' => 'redis', 'notifications.gateway.url' => 'https://gateway.test/notify']);
    clearDeliveryQueues();
});

afterEach(function () {
    clearDeliveryQueues();
});

test('a worker delivers high priority before normal before low, whatever order they were queued in', function () {
    $delivered = [];
    Http::fake(function (Request $request) use (&$delivered) {
        $delivered[] = $request['content'];

        return Http::response(['messageId' => 'msg-'.count($delivered)], 202);
    });
    $notifications = new Collection(array_map(
        fn (NotificationPriority $priority): Notification => Notification::factory()->create(['priority' => $priority, 'content' => $priority->value]),
        [NotificationPriority::Low, NotificationPriority::Normal, NotificationPriority::High],
    ));
    app(DispatchNotificationsAction::class)->handle($notifications);

    workQueues(NotificationPriority::queuesFor(NotificationChannel::Sms));

    expect($delivered)->toBe(['high', 'normal', 'low'])
        ->and($notifications->map(fn (Notification $notification): NotificationStatus => $notification->refresh()->status)->unique()->all())
        ->toBe([NotificationStatus::Sent]);
});

test('a worker of one channel leaves the jobs of another channel alone', function () {
    Http::fake(['gateway.test/*' => Http::response(['messageId' => 'msg-1'], 202)]);
    $sms = Notification::factory()->create();
    $email = Notification::factory()->email()->create();
    app(DispatchNotificationsAction::class)->handle(new Collection([$sms, $email]));

    workQueues(NotificationPriority::queuesFor(NotificationChannel::Sms));

    expect($sms->refresh()->status)->toBe(NotificationStatus::Sent)
        ->and($email->refresh()->status)->toBe(NotificationStatus::Queued)
        ->and(Redis::connection()->llen('queues:notifications-email-normal'))->toBe(1);
});

test('a failed attempt goes back to the queue as a delayed job, due at the notification\'s next attempt', function () {
    Http::fake(['gateway.test/*' => Http::response([], 503)]);
    $notification = Notification::factory()->create();
    app(DispatchNotificationsAction::class)->handle(new Collection([$notification]));

    workQueues(NotificationPriority::queuesFor(NotificationChannel::Sms));

    $notification->refresh();
    $delayed = Redis::connection()->zrange('queues:notifications-sms-normal:delayed', 0, -1, ['withscores' => true]);
    expect($notification->status)->toBe(NotificationStatus::Queued)
        ->and($notification->attempts)->toBe(1)
        ->and($delayed)->toHaveCount(1)
        ->and((int) array_values($delayed)[0])->toBe($notification->next_attempt_at->getTimestamp());
});

test('a worker drops the job of a notification cancelled after it was queued, without calling the provider', function () {
    Http::fake();
    $notification = Notification::factory()->create();
    app(DispatchNotificationsAction::class)->handle(new Collection([$notification]));
    $notification->cancel();

    workQueues(NotificationPriority::queuesFor(NotificationChannel::Sms));

    Http::assertNothingSent();
    expect($notification->refresh()->status)->toBe(NotificationStatus::Cancelled)
        ->and(Redis::connection()->llen('queues:notifications-sms-normal'))->toBe(0);
});
