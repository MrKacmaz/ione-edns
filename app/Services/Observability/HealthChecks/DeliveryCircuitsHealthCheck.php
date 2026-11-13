<?php

namespace App\Services\Observability\HealthChecks;

use App\Data\HealthCheckResultData;
use App\Enums\CircuitState;
use App\Enums\NotificationChannel;
use App\Services\Delivery\Contracts\CircuitBreaker;
use App\Services\Observability\Contracts\HealthCheck;

/**
 * A channel whose circuit is not closed delivers nothing until its provider recovers.
 *
 * The service is degraded rather than failing then: the API keeps accepting
 * notifications and the other channels keep delivering.
 */
final readonly class DeliveryCircuitsHealthCheck implements HealthCheck
{
    public function __construct(private CircuitBreaker $circuitBreaker) {}

    public function name(): string
    {
        return 'delivery_circuits';
    }

    public function check(): HealthCheckResultData
    {
        $pausedChannels = array_values(array_filter(
            NotificationChannel::cases(),
            fn (NotificationChannel $channel): bool => $this->circuitBreaker->state($channel) !== CircuitState::Closed,
        ));

        if ($pausedChannels === []) {
            return HealthCheckResultData::ok();
        }

        return HealthCheckResultData::degraded(__('health.circuits_open', [
            'channels' => implode(', ', array_map(fn (NotificationChannel $channel): string => $channel->value, $pausedChannels)),
        ]));
    }
}
