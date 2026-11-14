<?php

use App\Exceptions\GatewayNotConfiguredException;

test('tells that the gateway URL is missing', function () {
    $exception = GatewayNotConfiguredException::missingUrl();

    expect($exception->getMessage())->toBe('The notification gateway URL is not configured.');
});
