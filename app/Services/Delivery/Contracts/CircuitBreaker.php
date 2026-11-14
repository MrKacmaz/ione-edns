<?php

namespace App\Services\Delivery\Contracts;

use App\Data\CircuitPermitData;
use App\Enums\CircuitState;
use App\Enums\GatewayOutcome;
use App\Enums\NotificationChannel;

/**
 * Stops calling a channel's provider while it keeps failing, and probes it before calling it again.
 */
interface CircuitBreaker
{
    /**
     * Ask whether the channel's provider may be called now.
     */
    public function permit(NotificationChannel $channel): CircuitPermitData;

    /**
     * Record how the channel's provider answered a call.
     *
     * A call's outcome only ever opens or closes a circuit; half open is reached by
     * asking for a permit after the cooldown.
     *
     * @return CircuitState::Open|CircuitState::Closed|null The state the circuit moved to, or null when it did not change.
     */
    public function record(NotificationChannel $channel, GatewayOutcome $outcome): ?CircuitState;

    /**
     * Get the current state of the channel's circuit.
     */
    public function state(NotificationChannel $channel): CircuitState;
}
