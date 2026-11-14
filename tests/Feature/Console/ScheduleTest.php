<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

/**
 * Find the one scheduled event that runs the given Artisan command.
 */
function scheduledEventFor(string $command): Event
{
    return collect(app(Schedule::class)->events())
        ->sole(fn (Event $event): bool => Str::endsWith((string) $event->command, ' '.$command));
}

test('runs the delivery sweeps every minute on one server without overlapping', function (string $command) {
    $event = scheduledEventFor($command);

    expect($event)
        ->expression->toBe('* * * * *')
        ->repeatSeconds->toBeNull()
        ->withoutOverlapping->toBeTrue()
        ->expiresAt->toBe(5)
        ->onOneServer->toBeTrue();
})->with([
    'stale notification sweeper' => ['notifications:redispatch-stale'],
    'scheduled notification dispatcher' => ['notifications:dispatch-due'],
]);

test('broadcasts metrics every five seconds on one server without overlapping', function () {
    $event = scheduledEventFor('metrics:broadcast');

    expect($event)
        ->expression->toBe('* * * * *')
        ->repeatSeconds->toBe(5)
        ->withoutOverlapping->toBeTrue()
        ->expiresAt->toBe(1)
        ->onOneServer->toBeTrue();
});

test('takes Horizon snapshots and prunes models on their own schedule', function (string $command, string $expression) {
    $event = scheduledEventFor($command);

    expect($event->expression)->toBe($expression);
})->with([
    'Horizon metrics snapshot every five minutes' => ['horizon:snapshot', '*/5 * * * *'],
    'model pruning daily at midnight' => ['model:prune', '0 0 * * *'],
]);
