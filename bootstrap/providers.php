<?php

use App\Providers\AppServiceProvider;
use App\Providers\DeliveryServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\ObservabilityServiceProvider;

return [
    AppServiceProvider::class,
    DeliveryServiceProvider::class,
    HorizonServiceProvider::class,
    ObservabilityServiceProvider::class,
];
