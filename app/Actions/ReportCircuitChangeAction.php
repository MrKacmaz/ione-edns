<?php

namespace App\Actions;

use App\Enums\CircuitState;
use App\Enums\NotificationChannel;
use App\Events\CircuitStateChanged;
use App\Notifications\DeliveryCircuitChangedNotification;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Notifications\AnonymousNotifiable;
use Psr\Log\LoggerInterface;

/**
 * Logs that a channel's circuit opened or closed, tells open dashboards and alerts the operators.
 *
 * Mails are throttled per channel and change, so a flapping provider does not
 * flood the operators' inbox; the log, the metrics and the health endpoint
 * always show the current state.
 */
class ReportCircuitChangeAction
{
    /**
     * @param  list<string>  $alertMailTo
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CacheRepository $cache,
        #[Config('notifications.alerts.mail_to')] private readonly array $alertMailTo,
        #[Config('notifications.alerts.throttle_minutes')] private readonly int $throttleMinutes,
    ) {}

    /**
     * @param  CircuitState::Open|CircuitState::Closed  $state
     */
    public function handle(NotificationChannel $channel, CircuitState $state): void
    {
        if ($state === CircuitState::Open) {
            $this->logger->warning('notification.circuit.opened', ['channel' => $channel->value]);
        } else {
            $this->logger->info('notification.circuit.closed', ['channel' => $channel->value]);
        }

        CircuitStateChanged::dispatch($channel, $state);

        if ($this->alertMailTo === [] || ! $this->cache->add($this->throttleKey($channel, $state), true, now()->addMinutes($this->throttleMinutes))) {
            return;
        }

        (new AnonymousNotifiable)
            ->route('mail', $this->alertMailTo)
            ->notify(new DeliveryCircuitChangedNotification($channel, $state));
    }

    private function throttleKey(NotificationChannel $channel, CircuitState $state): string
    {
        return "notifications:circuit-alert:{$channel->value}:{$state->value}";
    }
}
