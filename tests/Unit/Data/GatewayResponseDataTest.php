<?php

use App\Data\GatewayResponseData;
use App\Enums\GatewayOutcome;

test('records an accepted attempt with the provider\'s status and message id', function () {
    $response = GatewayResponseData::accepted(202, 'provider-message-123');

    expect($response)
        ->outcome->toBe(GatewayOutcome::Accepted)
        ->httpStatus->toBe(202)
        ->providerMessageId->toBe('provider-message-123')
        ->error->toBeNull()
        ->retryAfterSeconds->toBeNull();
});

test('records a retryable transport failure without a status or Retry-After', function () {
    $response = GatewayResponseData::retryable('Connection timed out.');

    expect($response)
        ->outcome->toBe(GatewayOutcome::RetryableFailure)
        ->error->toBe('Connection timed out.')
        ->httpStatus->toBeNull()
        ->retryAfterSeconds->toBeNull()
        ->providerMessageId->toBeNull();
});

test('records a retryable provider failure with its status and Retry-After', function () {
    $response = GatewayResponseData::retryable('Too Many Requests', 429, 30);

    expect($response)
        ->outcome->toBe(GatewayOutcome::RetryableFailure)
        ->error->toBe('Too Many Requests')
        ->httpStatus->toBe(429)
        ->retryAfterSeconds->toBe(30)
        ->providerMessageId->toBeNull();
});

test('records a permanent failure with the provider\'s status and error', function () {
    $response = GatewayResponseData::permanent('Invalid recipient.', 400);

    expect($response)
        ->outcome->toBe(GatewayOutcome::PermanentFailure)
        ->error->toBe('Invalid recipient.')
        ->httpStatus->toBe(400)
        ->retryAfterSeconds->toBeNull()
        ->providerMessageId->toBeNull();
});
