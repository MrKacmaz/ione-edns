<?php

namespace Tests\Support;

use Random\Engine;

/**
 * A random engine that always yields zero, so a Randomizer always returns its lower bound.
 */
final class ZeroRandomEngine implements Engine
{
    public function generate(): string
    {
        return str_repeat("\0", 8);
    }
}
