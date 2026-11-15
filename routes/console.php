<?php

use App\Console\Commands\BroadcastMetricsCommand;
use App\Console\Commands\DispatchDueNotificationsCommand;
use App\Console\Commands\RedispatchStaleNotificationsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
|
| schedule:run runs the events that are due one after another and only then
| starts the loop of the five-second broadcast, so every event that is due at
| the start of a minute runs in the background; otherwise a long sweep would
| hold the dashboard's metrics back for most of that minute.
|
*/

Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->runInBackground();

Schedule::command('model:prune')
    ->daily()
    ->runInBackground();

Schedule::command(RedispatchStaleNotificationsCommand::class)
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground();

Schedule::command(DispatchDueNotificationsCommand::class)
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->runInBackground();

Schedule::command(BroadcastMetricsCommand::class)
    ->everyFiveSeconds()
    ->withoutOverlapping(1)
    ->onOneServer();
