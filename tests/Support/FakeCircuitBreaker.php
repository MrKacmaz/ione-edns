<?php

namespace Tests\Support;

use App\Data\CircuitPermitData;
use App\Enums\CircuitState;
use App\Enums\GatewayOutcome;
use App\Enums\NotificationChannel;
use App\Services\Delivery\Contracts\CircuitBreaker;

/**
 * A circuit breaker with preset answers that remembers the outcomes it was given.
 */
final class FakeCircuitBreaker implements CircuitBreaker
{
    /**
     * @var list<array{channel: NotificationChannel, outcome: GatewayOutcome}>
     */
    public array $recorded = [];

    /**
     * @var list<array{channel: NotificationChannel, permit: CircuitPermitData}>
     */
    public array $releasedProbes = [];

    /**
     * @param  CircuitPermitData|null  $permit  The answer to every permit request; granted when null.
     * @param  CircuitState|null  $transition  The state every recorded outcome moves the circuit to, if any.
     * @param  array<string, CircuitState>  $states  The state per channel value; closed when missing.
     */
    public function __construct(
        private readonly ?CircuitPermitData $permit = null,
        private readonly ?CircuitState $transition = null,
        private readonly array $states = [],
    ) {}

    public function permit(NotificationChannel $channel): CircuitPermitData
    {
        return $this->permit ?? CircuitPermitData::granted();
    }

    public function record(NotificationChannel $channel, GatewayOutcome $outcome): ?CircuitState
    {
        $this->recorded[] = ['channel' => $channel, 'outcome' => $outcome];

        return $this->transition;
    }

    public function releaseProbe(NotificationChannel $channel, CircuitPermitData $permit): void
    {
        $this->releasedProbes[] = ['channel' => $channel, 'permit' => $permit];
    }

    public function state(NotificationChannel $channel): CircuitState
    {
        return $this->states[$channel->value] ?? CircuitState::Closed;
    }
}
