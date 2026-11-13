<?php

namespace App\Services\Observability\HealthChecks;

use App\Data\HealthCheckResultData;
use App\Services\Observability\Contracts\HealthCheck;
use Illuminate\Container\Attributes\Config;

/**
 * Checks that the provider is configured; calling it would send a notification.
 */
final readonly class GatewayHealthCheck implements HealthCheck
{
    public function __construct(#[Config('notifications.gateway.url')] private ?string $url) {}

    public function name(): string
    {
        return 'gateway';
    }

    public function check(): HealthCheckResultData
    {
        return $this->url === null || $this->url === ''
            ? HealthCheckResultData::failing(__('health.gateway_not_configured'))
            : HealthCheckResultData::ok();
    }
}
