<?php

namespace App\Providers;

use App\Services\Delivery\ChannelRegistry;
use App\Services\Delivery\Channels\EmailChannel;
use App\Services\Delivery\Channels\PushChannel;
use App\Services\Delivery\Channels\SmsChannel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the notification delivery subsystem into the container.
 */
class DeliveryServiceProvider extends ServiceProvider
{
    /**
     * Register the delivery services.
     */
    public function register(): void
    {
        $this->registerChannels();
    }

    /**
     * Register every delivery channel in one shared registry.
     *
     * A new channel needs a case on the NotificationChannel enum, a DeliveryChannel
     * implementation and one register() call here.
     */
    private function registerChannels(): void
    {
        $this->app->singleton(ChannelRegistry::class, fn (Application $app): ChannelRegistry => (new ChannelRegistry)
            ->register($app->make(SmsChannel::class))
            ->register($app->make(EmailChannel::class))
            ->register($app->make(PushChannel::class)));
    }
}
