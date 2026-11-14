<?php

use App\Enums\NotificationStatus;

test('allows cancelling only notifications that no worker has claimed yet', function () {
    expect(NotificationStatus::cancellable())
        ->toHaveCount(3)
        ->toContain(NotificationStatus::Waiting, NotificationStatus::Scheduled, NotificationStatus::Queued);
});

test('lets workers claim only waiting and queued notifications', function () {
    expect(NotificationStatus::claimable())
        ->toHaveCount(2)
        ->toContain(NotificationStatus::Waiting, NotificationStatus::Queued);
});

test('treats sent, failed and cancelled as final statuses', function () {
    expect(NotificationStatus::final())
        ->toHaveCount(3)
        ->toContain(NotificationStatus::Sent, NotificationStatus::Failed, NotificationStatus::Cancelled);
});

test('reports whether a notification in the status may still be cancelled', function (NotificationStatus $status, bool $isCancellable) {
    expect($status->isCancellable())->toBe($isCancellable);
})->with([
    'waiting' => [NotificationStatus::Waiting, true],
    'scheduled' => [NotificationStatus::Scheduled, true],
    'queued' => [NotificationStatus::Queued, true],
    'processing' => [NotificationStatus::Processing, false],
    'sent' => [NotificationStatus::Sent, false],
    'failed' => [NotificationStatus::Failed, false],
    'cancelled' => [NotificationStatus::Cancelled, false],
]);
