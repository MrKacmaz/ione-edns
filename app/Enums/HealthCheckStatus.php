<?php

namespace App\Enums;

enum HealthCheckStatus: string
{
    case Ok = 'ok';
    case Failing = 'fail';
}
