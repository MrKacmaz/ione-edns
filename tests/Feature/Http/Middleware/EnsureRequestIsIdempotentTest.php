<?php

use App\Http\Middleware\EnsureRequestIsIdempotent;
use App\Jobs\SendNotificationJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

test('replays the stored response when a completed request is retried with the same key', function () {
    Queue::fake([SendNotificationJob::class]);
    Sanctum::actingAs(User::factory()->create());
    $headers = ['Idempotency-Key' => 'b2c1f0e4-9a57-4cc4-9e57-7f1b0d0a3c11'];

    $first = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(), $headers);
    $retry = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(), $headers);

    $first->assertAccepted()->assertHeaderMissing('Idempotent-Replayed');
    $retry->assertAccepted()->assertHeader('Idempotent-Replayed', 'true');
    expect($retry->getContent())->toBe($first->getContent());
    $this->assertDatabaseCount('notifications', 1);
    Queue::assertPushed(SendNotificationJob::class, 1);
});

test('treats a payload with reordered keys as the same request', function () {
    Queue::fake([SendNotificationJob::class]);
    Sanctum::actingAs(User::factory()->create());
    $headers = ['Idempotency-Key' => 'reordered-payload'];

    $this->postJson(route('api.v1.notifications.store'), ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hi'], $headers);
    $retry = $this->postJson(route('api.v1.notifications.store'), ['content' => 'Hi', 'recipient' => '+905551234567', 'channel' => 'sms'], $headers);

    $retry->assertAccepted()->assertHeader('Idempotent-Replayed', 'true');
    $this->assertDatabaseCount('notifications', 1);
    Queue::assertPushed(SendNotificationJob::class, 1);
});

test('returns 422 when the key is reused with a different payload', function () {
    Queue::fake([SendNotificationJob::class]);
    Sanctum::actingAs(User::factory()->create());
    $headers = ['Idempotency-Key' => 'reused-key'];
    $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(), $headers);

    $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(['content' => 'Another text']), $headers);

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'This Idempotency-Key was already used with a different request payload.');
    $this->assertDatabaseCount('notifications', 1);
    Queue::assertPushed(SendNotificationJob::class, 1);
});

test('returns 409 while the first request with the key is still in progress', function () {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    Cache::lock(EnsureRequestIsIdempotent::lockName($user->id, 'in-flight-key'), 30)->get();

    $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(), ['Idempotency-Key' => 'in-flight-key']);

    $response->assertConflict()
        ->assertJsonPath('message', 'A request with this Idempotency-Key is still being processed. Retry later.');
    $this->assertDatabaseCount('notifications', 0);
});

test('lets a corrected request reuse the key of a request that failed validation', function () {
    Queue::fake([SendNotificationJob::class]);
    Sanctum::actingAs(User::factory()->create());
    $headers = ['Idempotency-Key' => 'corrected-request'];
    $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(['recipient' => 'not-a-phone']), $headers)
        ->assertUnprocessable();

    $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(), $headers);

    $response->assertAccepted()->assertHeaderMissing('Idempotent-Replayed');
    $this->assertDatabaseCount('notifications', 1);
    Queue::assertPushed(SendNotificationJob::class, 1);
});

test('keeps the keys of different users apart', function () {
    Queue::fake([SendNotificationJob::class]);
    $headers = ['Idempotency-Key' => 'shared-key'];
    Sanctum::actingAs(User::factory()->create());
    $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(), $headers);
    Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(), $headers);

    $response->assertAccepted()->assertHeaderMissing('Idempotent-Replayed');
    $this->assertDatabaseCount('notifications', 2);
    Queue::assertPushed(SendNotificationJob::class, 2);
});

test('handles the request again once the stored response has expired', function () {
    Queue::fake([SendNotificationJob::class]);
    Sanctum::actingAs(User::factory()->create());
    $headers = ['Idempotency-Key' => 'expiring-key'];
    $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(), $headers);
    $this->travel(25)->hours();

    $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(), $headers);

    $response->assertAccepted()->assertHeaderMissing('Idempotent-Replayed');
    $this->assertDatabaseCount('notifications', 2);
    Queue::assertPushed(SendNotificationJob::class, 2);
    $this->assertDatabaseCount('idempotency_keys', 1);
});

test('keeps a response replayable only for the configured retention', function () {
    Queue::fake([SendNotificationJob::class]);
    config(['notifications.idempotency.retention_hours' => 1]);
    Sanctum::actingAs(User::factory()->create());
    $headers = ['Idempotency-Key' => 'short-retention-key'];
    $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(), $headers);

    $this->travel(59)->minutes();
    $withinRetention = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(), $headers);
    $this->travel(2)->minutes();
    $afterRetention = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(), $headers);

    $withinRetention->assertHeader('Idempotent-Replayed', 'true');
    $afterRetention->assertAccepted()->assertHeaderMissing('Idempotent-Replayed');
    $this->assertDatabaseCount('notifications', 2);
    Queue::assertPushed(SendNotificationJob::class, 2);
});

test('creates a new notification for every request without a key', function () {
    Queue::fake([SendNotificationJob::class]);
    Sanctum::actingAs(User::factory()->create());

    $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload())->assertAccepted();
    $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload())->assertAccepted();

    $this->assertDatabaseCount('notifications', 2);
    Queue::assertPushed(SendNotificationJob::class, 2);
    $this->assertDatabaseCount('idempotency_keys', 0);
});

test('returns 400 for a key that is empty or longer than 255 characters', function (string $key) {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(), ['Idempotency-Key' => $key]);

    $response->assertBadRequest()
        ->assertJsonPath('message', 'The Idempotency-Key header must be a non-empty string of at most 255 characters.');
    $this->assertDatabaseCount('notifications', 0);
})->with([
    'empty' => [''],
    'too long' => [str_repeat('k', 256)],
]);

test('replays a completed batch request', function () {
    Queue::fake([SendNotificationJob::class]);
    Sanctum::actingAs(User::factory()->create());
    $headers = ['Idempotency-Key' => 'batch-request'];
    $payload = ['notifications' => [smsNotificationPayload(), smsNotificationPayload()]];

    $first = $this->postJson(route('api.v1.notification-batches.store'), $payload, $headers);
    $retry = $this->postJson(route('api.v1.notification-batches.store'), $payload, $headers);

    $retry->assertAccepted()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.id', $first->json('data.id'));
    $this->assertDatabaseCount('notification_batches', 1);
    $this->assertDatabaseCount('notifications', 2);
    Queue::assertPushed(SendNotificationJob::class, 2);
});
