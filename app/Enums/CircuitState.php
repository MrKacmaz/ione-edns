<?php

namespace App\Enums;

/**
 * Whether a channel's provider may be called.
 */
enum CircuitState: string
{
    /** The provider is healthy; every call goes through. */
    case Closed = 'closed';

    /** Too many calls failed; no call goes through until the cooldown ends. */
    case Open = 'open';

    /** The cooldown ended; a single probe call decides whether the circuit closes. */
    case HalfOpen = 'half_open';
}
