<?php

namespace App\Services\Observability;

use App\Enums\CircuitState;
use App\Enums\HealthCheckStatus;
use App\Enums\NotificationChannel;
use App\Services\Delivery\Contracts\ChannelRateLimiter;
use App\Services\Delivery\Contracts\CircuitBreaker;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\DatabaseManager;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Checks the dependencies the service needs to deliver notifications.
 *
 * A check that throws counts as failed with a generic message; the exception
 * itself is only reported, because the health endpoint is public.
 *
 * @phpstan-type CheckResult array{status: HealthCheckStatus, message: string|null}
 */
final readonly class HealthChecker
{
    public function __construct(
        private DatabaseManager $database,
        private RedisFactory $redis,
        private MasterSupervisorRepository $horizonMasters,
        private CircuitBreaker $circuitBreaker,
        private ChannelRateLimiter $rateLimiter,
        #[Config('notifications.gateway.url')] private ?string $gatewayUrl,
    ) {}

    /**
     * Run every check, in the order the health endpoint reports them.
     *
     * @return array<string, CheckResult>
     */
    public function run(): array
    {
        $checks = [
            'database' => $this->checkDatabase(...),
            'redis' => $this->checkRedis(...),
            'horizon' => $this->checkHorizon(...),
            'gateway' => $this->checkGateway(...),
            'delivery_circuits' => $this->checkDeliveryCircuits(...),
            'delivery_channels' => $this->checkDeliveryChannels(...),
        ];

        $results = [];

        foreach ($checks as $name => $check) {
            try {
                $results[$name] = $check();
            } catch (Throwable $exception) {
                report($exception);

                $results[$name] = self::result(HealthCheckStatus::Failing, __('health.check_failed', ['check' => $name]));
            }
        }

        return $results;
    }

    /**
     * Rate the service by its worst check: failing if any check fails,
     * degraded if any check is degraded, and ok otherwise.
     *
     * @param  array<string, CheckResult>  $results
     */
    public static function overallStatus(array $results): HealthCheckStatus
    {
        $statuses = array_column($results, 'status');

        return match (true) {
            in_array(HealthCheckStatus::Failing, $statuses, true) => HealthCheckStatus::Failing,
            in_array(HealthCheckStatus::Degraded, $statuses, true) => HealthCheckStatus::Degraded,
            default => HealthCheckStatus::Ok,
        };
    }

    /**
     * @return CheckResult
     */
    private function checkDatabase(): array
    {
        $this->database->connection()->select('select 1');

        return self::result(HealthCheckStatus::Ok);
    }

    /**
     * Redis carries the queues, the rate limiter, the cache and the idempotency locks.
     *
     * @return CheckResult
     */
    private function checkRedis(): array
    {
        $this->redis->connection()->ping();

        return self::result(HealthCheckStatus::Ok);
    }

    /**
     * Without a running, unpaused Horizon master nothing is delivered, although the API keeps accepting notifications.
     *
     * @return CheckResult
     */
    private function checkHorizon(): array
    {
        $masters = $this->horizonMasters->all();

        if ($masters === []) {
            return self::result(HealthCheckStatus::Failing, __('health.horizon_inactive'));
        }

        // `horizon:pause` keeps the master registered while its workers take no jobs.
        foreach ($masters as $master) {
            if (($master->status ?? null) !== 'paused') {
                return self::result(HealthCheckStatus::Ok);
            }
        }

        return self::result(HealthCheckStatus::Failing, __('health.horizon_paused'));
    }

    /**
     * Only checks that the provider is configured; calling it would send a notification.
     *
     * @return CheckResult
     */
    private function checkGateway(): array
    {
        return $this->gatewayUrl === null || $this->gatewayUrl === ''
            ? self::result(HealthCheckStatus::Failing, __('health.gateway_not_configured'))
            : self::result(HealthCheckStatus::Ok);
    }

    /**
     * A channel whose circuit is not closed delivers nothing until its provider recovers.
     *
     * The service is degraded rather than failing then: the API keeps accepting
     * notifications and the other channels keep delivering.
     *
     * @return CheckResult
     */
    private function checkDeliveryCircuits(): array
    {
        $pausedChannels = array_filter(
            NotificationChannel::cases(),
            fn (NotificationChannel $channel): bool => $this->circuitBreaker->state($channel) !== CircuitState::Closed,
        );

        if ($pausedChannels === []) {
            return self::result(HealthCheckStatus::Ok);
        }

        return self::result(HealthCheckStatus::Degraded, __('health.circuits_open', [
            'channels' => implode(', ', array_map(fn (NotificationChannel $channel): string => $channel->value, $pausedChannels)),
        ]));
    }

    /**
     * Warn about channels an operator paused with a rate limit of 0.
     *
     * @return CheckResult
     */
    private function checkDeliveryChannels(): array
    {
        $pausedChannels = array_filter(
            NotificationChannel::cases(),
            fn (NotificationChannel $channel): bool => $this->rateLimiter->limitPerSecond($channel) === 0,
        );

        if ($pausedChannels === []) {
            return self::result(HealthCheckStatus::Ok);
        }

        return self::result(HealthCheckStatus::Degraded, __('health.channels_paused', [
            'channels' => implode(', ', array_map(fn (NotificationChannel $channel): string => $channel->value, $pausedChannels)),
        ]));
    }

    /**
     * @return CheckResult
     */
    private static function result(HealthCheckStatus $status, ?string $message = null): array
    {
        return ['status' => $status, 'message' => $message];
    }
}
