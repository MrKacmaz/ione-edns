<?php

use App\Enums\NotificationChannel;
use App\Exceptions\UnregisteredChannelException;

test('names the channel that has no delivery channel registered', function () {
    $exception = UnregisteredChannelException::for(NotificationChannel::Email);

    expect($exception->getMessage())->toBe('No delivery channel is registered for the [email] channel.');
});
