<?php

namespace App\Actions;

use App\Enums\CircuitState;
use App\Enums\NotificationChannel;
use App\Events\CircuitStateChanged;
use Psr\Log\LoggerInterface;

/**
 * Logs that a channel's circuit opened or closed and tells open dashboards.
 *
 * The log, the metrics and the health endpoint always show the current state.
 */
class ReportCircuitChangeAction
{
    public function __construct(private readonly LoggerInterface $logger) {}

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
    }
}
