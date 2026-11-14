<?php

use App\Enums\NotificationChannel;
use App\Exceptions\UnregisteredChannelException;
use App\Services\Delivery\ChannelRegistry;
use App\Services\Delivery\Channels\EmailChannel;
use App\Services\Delivery\Channels\SmsChannel;

test('resolves a delivery channel by the notification channel it handles', function () {
    $sms = new SmsChannel;
    $email = new EmailChannel;

    $registry = (new ChannelRegistry)->register($sms)->register($email);

    expect($registry->for(NotificationChannel::Sms))->toBe($sms)
        ->and($registry->for(NotificationChannel::Email))->toBe($email);
});

test('throws when no delivery channel is registered for the notification channel', function () {
    $registry = (new ChannelRegistry)->register(new SmsChannel);

    $registry->for(NotificationChannel::Push);
})->throws(UnregisteredChannelException::class, 'No delivery channel is registered for the [push] channel.');
