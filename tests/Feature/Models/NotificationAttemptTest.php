<?php

use App\Enums\GatewayOutcome;
use App\Models\Notification;

test('records the attempt time to the microsecond, for latency metrics, and keeps no update time', function () {
    $this->travelTo('2026-11-14 10:00:00.123456');
    $notification = Notification::factory()->processing()->create();

    $attempt = $notification->deliveryAttempts()->create([
        'attempt' => 1,
        'outcome' => GatewayOutcome::Accepted,
        'http_status' => 202,
        'provider_message_id' => 'msg-123',
        'duration_ms' => 120,
    ]);

    expect($attempt->getAttributes())->not->toHaveKey('updated_at');
    $this->assertDatabaseHas('notification_attempts', [
        'id' => $attempt->id,
        'notification_id' => $notification->id,
        'created_at' => '2026-11-14 10:00:00.123456',
    ]);
});
