<?php

namespace App\Enums;

enum HealthCheckStatus: string
{
    case Ok = 'ok';

    /** Working, but with reduced service; the endpoint still answers 200. */
    case Degraded = 'warn';

    case Failing = 'fail';
}
