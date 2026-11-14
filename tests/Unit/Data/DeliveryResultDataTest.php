<?php

use App\Data\DeliveryResultData;
use App\Enums\DeliveryOutcome;

test('carries no retry delay for a run that schedules no retry', function (DeliveryResultData $result, DeliveryOutcome $expectedOutcome) {
    expect($result)
        ->outcome->toBe($expectedOutcome)
        ->retryDelaySeconds->toBeNull();
})->with([
    'sent' => [DeliveryResultData::sent(), DeliveryOutcome::Sent],
    'failed for good' => [DeliveryResultData::failed(), DeliveryOutcome::Failed],
    'skipped' => [DeliveryResultData::skipped(), DeliveryOutcome::Skipped],
]);

test('carries the delay until the scheduled retry', function () {
    $result = DeliveryResultData::retryScheduled(90);

    expect($result)
        ->outcome->toBe(DeliveryOutcome::RetryScheduled)
        ->retryDelaySeconds->toBe(90);
});
