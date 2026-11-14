<?php

use App\Http\Middleware\EnsureRequestIsIdempotent;
use App\Jobs\SendNotificationJob;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
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

test('rolls back a request that lost the race for its key and answers with the winner\'s response', function () {
    Queue::fake([SendNotificationJob::class]);
    User::query()->count();

    // The competing request runs on a second connection, so that what it stores is
    // committed and visible while this request is still in its transaction.
    config(['database.connections.competing' => config('database.connections.'.config('database.default'))]);
    $competing = DB::connection('competing');
    $userId = $competing->table('users')->insertGetId([
        'name' => 'Racing User',
        'email' => 'racing-user@example.com',
        'password' => 'not-used',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    try {
        Sanctum::actingAs(User::query()->findOrFail($userId));
        $payload = smsNotificationPayload();
        $fingerprint = (fn (Request $request): string => $this->fingerprint($request))->call(
            app(EnsureRequestIsIdempotent::class),
            Request::create('/api/v1/notifications', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR)),
        );

        // The winner commits its response with the same key after this request has stored its notification.
        $winnerStored = false;
        DB::listen(function (QueryExecuted $query) use ($competing, $userId, $fingerprint, &$winnerStored): void {
            if ($winnerStored || $query->connectionName === 'competing' || ! str_starts_with($query->sql, 'insert into "notifications"')) {
                return;
            }

            $winnerStored = true;
            $competing->table('idempotency_keys')->insert([
                'user_id' => $userId,
                'key' => 'raced-key',
                'request_fingerprint' => $fingerprint,
                'response_status' => 202,
                'response_body' => '{"data":{"id":"stored-by-the-winner"}}',
                'expires_at' => now()->addDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $response = $this->postJson(route('api.v1.notifications.store'), $payload, ['Idempotency-Key' => 'raced-key']);

        expect($winnerStored)->toBeTrue();
        $response->assertAccepted()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertExactJson(['data' => ['id' => 'stored-by-the-winner']]);
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    } finally {
        $competing->table('users')->where('id', $userId)->delete();
        DB::purge('competing');
    }
});

test('accepts and replays a request while the queue is unreachable instead of creating the notification twice', function () {
    Exceptions::fake();
    Queue::swap(new class(app()) extends QueueFake
    {
        public function push($job, $data = '', $queue = null): never
        {
            throw new RuntimeException('Connection refused');
        }
    });
    Sanctum::actingAs(User::factory()->create());
    $headers = ['Idempotency-Key' => 'queue-outage'];

    $first = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(), $headers);
    $retry = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(), $headers);

    $first->assertAccepted()->assertJsonPath('data.status', 'waiting');
    $retry->assertAccepted()->assertHeader('Idempotent-Replayed', 'true');
    $this->assertDatabaseCount('notifications', 1);
});
