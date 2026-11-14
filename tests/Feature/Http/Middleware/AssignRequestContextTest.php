<?php

use App\Jobs\SendNotificationJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

test('keeps a correlation id the client sent as a UUID', function () {
    $correlationId = '0F8FAD5B-D9CB-469F-A165-70867728950E';

    $response = $this->getJson(route('api.v1.notifications.index'), ['X-Correlation-ID' => $correlationId]);

    $response->assertOk()->assertHeader('X-Correlation-ID', '0f8fad5b-d9cb-469f-a165-70867728950e');
});

test('starts a new correlation id when the client sent none or no UUID', function (array $headers) {
    $response = $this->getJson(route('api.v1.notifications.index'), $headers);

    $correlationId = $response->headers->get('X-Correlation-ID');
    expect($correlationId)->toBeUuid()
        ->not->toBe($headers['X-Correlation-ID'] ?? null);
})->with([
    'no header' => [[]],
    'not a uuid' => [['X-Correlation-ID' => 'order-1001']],
    'empty' => [['X-Correlation-ID' => '']],
]);

test('stores the request\'s correlation id on the notifications it creates', function () {
    Queue::fake([SendNotificationJob::class]);
    $correlationId = (string) Str::uuid7();

    $response = $this->postJson(route('api.v1.notification-batches.store'), [
        'notifications' => [smsNotificationPayload(), smsNotificationPayload()],
    ], ['X-Correlation-ID' => $correlationId]);

    $response->assertAccepted()
        ->assertJsonPath('notifications.*.correlation_id', [$correlationId, $correlationId]);
    $this->assertDatabaseHas('notifications', ['id' => $response->json('notifications.0.id'), 'correlation_id' => $correlationId]);
    Queue::assertPushed(SendNotificationJob::class, 2);
});
