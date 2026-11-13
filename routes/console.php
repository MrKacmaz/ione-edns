<?php

use App\Console\Commands\DispatchDueNotificationsCommand;
use App\Console\Commands\RedispatchStaleNotificationsCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('horizon:snapshot')->everyFiveMinutes();

Schedule::command('model:prune')->daily();

Schedule::command(RedispatchStaleNotificationsCommand::class)
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

Schedule::command(DispatchDueNotificationsCommand::class)
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();
