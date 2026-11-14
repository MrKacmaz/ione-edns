<?php

use App\Enums\NotificationChannel;
use App\Enums\NotificationPriority;

/**
 * Get the Horizon supervisors that work at least one queue of the given channel.
 *
 * @return array<string, list<string>> The queues of each supervisor, by supervisor name.
 */
function supervisorsServing(NotificationChannel $channel): array
{
    $channelQueues = NotificationPriority::queuesFor($channel);

    return collect(config('horizon.defaults'))
        ->map(fn (array $supervisor): array => $supervisor['queue'])
        ->filter(fn (array $queues): bool => array_intersect($queues, $channelQueues) !== [])
        ->all();
}

test('gives every channel workers of its own, so one channel waiting for send slots never holds up another', function (NotificationChannel $channel) {
    $otherChannelQueues = collect(NotificationChannel::cases())
        ->reject(fn (NotificationChannel $other): bool => $other === $channel)
        ->flatMap(NotificationPriority::queuesFor(...))
        ->all();

    foreach (supervisorsServing($channel) as $queues) {
        expect(array_intersect($queues, $otherChannelQueues))->toBe([]);
    }
})->with(NotificationChannel::cases());

test('drains a channel\'s queues strictly from high to low priority', function (NotificationChannel $channel) {
    expect(config("horizon.defaults.supervisor-{$channel->value}"))
        ->queue->toBe(NotificationPriority::queuesFor($channel))
        ->balance->toBeFalse();
})->with(NotificationChannel::cases());

test('reserves workers for normal and for low priority, so neither starves and normal never gets fewer than low', function (NotificationChannel $channel) {
    $dedicatedQueues = collect(supervisorsServing($channel))
        ->filter(fn (array $queues): bool => count($queues) === 1)
        ->flatten()
        ->sort()
        ->values()
        ->all();

    expect($dedicatedQueues)->toBe([
        NotificationPriority::Low->queueFor($channel),
        NotificationPriority::Normal->queueFor($channel),
    ]);
})->with(NotificationChannel::cases());

test('warns about a long wait on every notification queue, sooner for higher priorities', function (NotificationChannel $channel) {
    $waits = array_map(
        fn (string $queue): ?int => config('horizon.waits')['redis:'.$queue] ?? null,
        NotificationPriority::queuesFor($channel),
    );

    expect($waits)->toBe([30, 120, 600]);
})->with(NotificationChannel::cases());
