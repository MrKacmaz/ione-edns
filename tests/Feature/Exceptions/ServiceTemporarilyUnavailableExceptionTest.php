<?php

use App\Exceptions\ServiceTemporarilyUnavailableException;

test('answers 503 and asks the client to retry in a few seconds, keeping the outage as its cause', function () {
    $outage = new RedisException('Connection refused');

    $exception = ServiceTemporarilyUnavailableException::causedBy($outage);

    expect($exception->getStatusCode())->toBe(503)
        ->and($exception->getHeaders())->toBe(['Retry-After' => 10])
        ->and($exception->getMessage())->toBe('The service is temporarily unavailable. Retry the request in a few seconds.')
        ->and($exception->getPrevious())->toBe($outage);
});
