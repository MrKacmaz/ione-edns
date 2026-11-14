<?php

use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

describe('store', function () {
    test('returns 401 when no token is provided', function () {
        $this->postJson(route('api.v1.notification-batches.store'), ['notifications' => [smsNotificationPayload()]])
            ->assertUnauthorized();

        $this->assertDatabaseCount('notification_batches', 0);
    });

    test('accepts every notification in one batch and returns them in request order', function () {
        Queue::fake([SendNotificationJob::class]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            'notifications' => [
                smsNotificationPayload(['priority' => 'high']),
                ['channel' => 'email', 'recipient' => 'customer@example.com', 'content' => 'Your invoice is ready.'],
                ['channel' => 'push', 'recipient' => str_repeat('a1', 16), 'content' => 'Flash sale starts now!', 'priority' => 'low'],
            ],
        ]);

        $batchId = $response->json('data.id');
        $response->assertAccepted()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.status_counts.queued', 3)
            ->assertJsonPath('data.is_finished', false)
            ->assertJsonPath('notifications.*.channel', ['sms', 'email', 'push'])
            ->assertJsonPath('notifications.*.priority', ['high', 'normal', 'low'])
            ->assertJsonPath('notifications.*.batch_id', [$batchId, $batchId, $batchId]);
        $this->assertDatabaseHas('notification_batches', ['id' => $batchId, 'user_id' => $user->id]);
        expect(Notification::query()->where('batch_id', $batchId)->where('user_id', $user->id)->count())->toBe(3);
        Queue::assertPushedOn('notifications-sms-high', SendNotificationJob::class);
        Queue::assertPushedOn('notifications-email-normal', SendNotificationJob::class);
        Queue::assertPushedOn('notifications-push-low', SendNotificationJob::class);
    });

    test('renders each notification from its own template, loading the templates with one query', function () {
        Queue::fake([SendNotificationJob::class]);
        $user = User::factory()->create();
        NotificationTemplate::factory()->for($user)->create(['name' => 'order-shipped']);
        NotificationTemplate::factory()->for($user)->create(['name' => 'welcome', 'content' => 'Welcome, {{ name }}!']);
        Sanctum::actingAs($user);
        DB::enableQueryLog();

        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            'notifications' => [
                ['channel' => 'sms', 'recipient' => '+905551234567', 'template' => 'order-shipped', 'variables' => ['name' => 'Ada', 'order_id' => 1]],
                ['channel' => 'sms', 'recipient' => '+905551234567', 'template' => 'welcome', 'variables' => ['name' => 'Grace']],
                ['channel' => 'sms', 'recipient' => '+905551234567', 'template' => 'order-shipped', 'variables' => ['name' => 'Alan', 'order_id' => 2]],
                smsNotificationPayload(),
            ],
        ]);

        $response->assertAccepted()->assertJsonPath('notifications.*.content', [
            'Hi Ada, your order 1 has shipped.',
            'Welcome, Grace!',
            'Hi Alan, your order 2 has shipped.',
            'Your order has shipped.',
        ]);
        $templateQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "notification_templates"'));
        expect($templateQueries)->toHaveCount(1);
        Queue::assertPushed(SendNotificationJob::class, 4);
    });

    test('reports a template error at the position of the notification', function () {
        $user = User::factory()->create();
        NotificationTemplate::factory()->for($user)->create(['name' => 'order-shipped']);
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            'notifications' => [
                smsNotificationPayload(),
                ['channel' => 'sms', 'recipient' => '+905551234567', 'template' => 'order-shipped', 'variables' => ['name' => 'Ada']],
            ],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'notifications.1.variables' => 'The notifications.1.variables must give a text or number value to: order_id.',
        ]);
        $this->assertDatabaseCount('notifications', 0);
    });

    test('schedules each notification of a batch on its own', function () {
        Queue::fake([SendNotificationJob::class]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            'notifications' => [
                smsNotificationPayload(),
                smsNotificationPayload(['scheduled_at' => now()->addDay()->toIso8601String()]),
            ],
        ]);

        $response->assertAccepted()
            ->assertJsonPath('notifications.*.status', ['queued', 'scheduled'])
            ->assertJsonPath('data.status_counts.queued', 1)
            ->assertJsonPath('data.status_counts.scheduled', 1);
        Queue::assertPushed(SendNotificationJob::class, 1);
        Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->notificationId === $response->json('notifications.0.id'));
    });

    test('rejects the whole batch when one delivery time is in the past', function () {
        Queue::fake([SendNotificationJob::class]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            'notifications' => [
                smsNotificationPayload(),
                smsNotificationPayload(['scheduled_at' => now()->subHour()->toIso8601String()]),
            ],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('notifications.1.scheduled_at');
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    });

    test('rejects the whole batch when one notification is invalid', function () {
        Queue::fake([SendNotificationJob::class]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            'notifications' => [
                smsNotificationPayload(),
                smsNotificationPayload(['recipient' => '05551234567']),
            ],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'notifications.1.recipient' => 'The notifications.1.recipient must be an E.164 phone number (e.g. +905551234567) for the sms channel.',
        ]);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('notification_batches', 0);
        Queue::assertNothingPushed();
    });

    test('rejects a batch with more than 1000 notifications', function () {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            'notifications' => array_fill(0, 1001, smsNotificationPayload()),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'notifications' => 'The notifications field must not have more than 1000 items.',
        ]);
    });

    test('rejects a batch larger than the configured maximum', function () {
        config(['notifications.batch.max_size' => 2]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            'notifications' => array_fill(0, 3, smsNotificationPayload()),
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'notifications' => 'The notifications field must not have more than 2 items.',
        ]);
        $this->assertDatabaseCount('notifications', 0);
    });

    test('answers a body larger than PHP accepts with a JSON 413 instead of an empty request', function () {
        Queue::fake([SendNotificationJob::class]);
        Sanctum::actingAs(User::factory()->create());
        $content = json_encode(['notifications' => [smsNotificationPayload()]], JSON_THROW_ON_ERROR);

        // The test client computes Content-Length itself, so the declared length is set on the server variables.
        $response = $this->call('POST', route('api.v1.notification-batches.store'), server: [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => (string) (ini_parse_quantity((string) ini_get('post_max_size')) + 1),
        ], content: $content);

        $response->assertStatus(413)->assertJsonPath('message', 'The POST data is too large.');
        $this->assertDatabaseCount('notifications', 0);
    });

    test('returns the existing notification for an idempotency key that was already used', function () {
        Queue::fake([SendNotificationJob::class]);
        $user = User::factory()->create();
        $existing = Notification::factory()->for($user)->withIdempotencyKey('campaign-42-user-7')->create([
            'recipient' => '+905551234567',
            'content' => 'Your order has shipped.',
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            'notifications' => [
                smsNotificationPayload(['idempotency_key' => 'campaign-42-user-7']),
                smsNotificationPayload(['idempotency_key' => 'campaign-42-user-8']),
            ],
        ]);

        $response->assertAccepted()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('notifications.0.id', $existing->id);
        Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->notificationId === $response->json('notifications.1.id'));
    });

    test('returns 422 for the notification whose idempotency key was already used for a different one', function () {
        $user = User::factory()->create();
        Notification::factory()->for($user)->withIdempotencyKey('campaign-42-user-7')->create([
            'recipient' => '+905551234567',
            'content' => 'Your order has shipped.',
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            'notifications' => [
                smsNotificationPayload(),
                smsNotificationPayload(['idempotency_key' => 'campaign-42-user-7', 'content' => 'A different text']),
            ],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'notifications.1.idempotency_key' => 'This idempotency key was already used for a different notification.',
        ]);
        $this->assertDatabaseCount('notification_batches', 0);
        $this->assertDatabaseCount('notifications', 1);
    });

    test('explains with 422 why the list of notifications is invalid', function (array $payload, string $invalidField, string $message) {
        Queue::fake([SendNotificationJob::class]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notification-batches.store'), $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors([$invalidField => $message]);
        $this->assertDatabaseCount('notification_batches', 0);
        Queue::assertNothingPushed();
    })->with([
        'no notifications' => [[], 'notifications', 'The notifications field is required.'],
        'empty list' => [['notifications' => []], 'notifications', 'The notifications field is required.'],
        'notifications that are not an array' => [['notifications' => 'all'], 'notifications', 'The notifications field must be an array.'],
        'notifications keyed by name' => [
            ['notifications' => ['first' => smsNotificationPayload()]],
            'notifications',
            'The notifications field must be a list.',
        ],
        'missing notification' => [['notifications' => [null]], 'notifications.0', 'The notifications.0 field is required.'],
        'notification that is not an object' => [['notifications' => ['hello']], 'notifications.0', 'The notifications.0 field must be an array.'],
    ]);

    test('explains with 422 which required fields of a notification are missing', function () {
        Queue::fake([SendNotificationJob::class]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            'notifications' => [['priority' => 'high']],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'notifications.0.channel' => 'The notifications.0.channel field is required.',
            'notifications.0.recipient' => 'The notifications.0.recipient field is required.',
            'notifications.0.content' => 'The notifications.0.content field is required when notifications.0.template is not present.',
        ]);
        $this->assertDatabaseCount('notification_batches', 0);
        Queue::assertNothingPushed();
    });

    test('explains with 422 why an idempotency key is invalid', function (array $notifications, string $invalidField, string $message) {
        Queue::fake([SendNotificationJob::class]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notification-batches.store'), ['notifications' => $notifications]);

        $response->assertUnprocessable()->assertJsonValidationErrors([$invalidField => $message]);
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    })->with([
        'repeated in the batch' => [
            [
                smsNotificationPayload(['idempotency_key' => 'campaign-42-user-7']),
                smsNotificationPayload(['idempotency_key' => 'campaign-42-user-7']),
            ],
            'notifications.1.idempotency_key',
            'The notifications.1.idempotency_key field has a duplicate value.',
        ],
        'not a string' => [
            [smsNotificationPayload(['idempotency_key' => 1001])],
            'notifications.0.idempotency_key',
            'The notifications.0.idempotency_key field must be a string.',
        ],
        'longer than 255 characters' => [
            [smsNotificationPayload(['idempotency_key' => str_repeat('a', 256)])],
            'notifications.0.idempotency_key',
            'The notifications.0.idempotency_key field must not be greater than 255 characters.',
        ],
    ]);

    test('assigns the batch and its notifications to the token owner even when another user id is sent', function () {
        Queue::fake([SendNotificationJob::class]);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.notification-batches.store'), [
            'user_id' => $otherUser->id,
            'notifications' => [smsNotificationPayload(['user_id' => $otherUser->id])],
        ]);

        $response->assertAccepted();
        $this->assertDatabaseHas('notification_batches', ['id' => $response->json('data.id'), 'user_id' => $user->id]);
        $this->assertDatabaseHas('notifications', ['id' => $response->json('notifications.0.id'), 'user_id' => $user->id]);
        Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->notificationId === $response->json('notifications.0.id'));
    });
});

describe('show', function () {
    test('returns the batch with its notification count per status', function () {
        $batch = NotificationBatch::factory()->create();
        Notification::factory()->inBatch($batch)->count(2)->create();
        Notification::factory()->inBatch($batch)->sent()->create();
        Sanctum::actingAs($batch->user);

        $response = $this->getJson(route('api.v1.notification-batches.show', $batch));

        $response->assertOk()
            ->assertJsonPath('data.id', $batch->id)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.status_counts', [
                'waiting' => 2,
                'scheduled' => 0,
                'queued' => 0,
                'processing' => 0,
                'sent' => 1,
                'failed' => 0,
                'cancelled' => 0,
            ])
            ->assertJsonPath('data.is_finished', false);
    });

    test('reports a batch as finished when every notification reached a final status', function () {
        $batch = NotificationBatch::factory()->create();
        Notification::factory()->inBatch($batch)->sent()->create();
        Notification::factory()->inBatch($batch)->failed()->create();
        Notification::factory()->inBatch($batch)->cancelled()->create();
        Sanctum::actingAs($batch->user);

        $response = $this->getJson(route('api.v1.notification-batches.show', $batch));

        $response->assertOk()->assertJsonPath('data.is_finished', true);
    });

    test('returns 404 for another user\'s batch', function () {
        $batch = NotificationBatch::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(route('api.v1.notification-batches.show', $batch))->assertNotFound();
    });

    test('returns 401 when no token is provided', function () {
        $batch = NotificationBatch::factory()->create();

        $this->getJson(route('api.v1.notification-batches.show', $batch))->assertUnauthorized();
    });

    test('returns 404 for an id that is not a uuid', function () {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(route('api.v1.notification-batches.show', 'not-a-uuid'))->assertNotFound();
    });

    test('returns exactly the fields of the batch contract', function () {
        $batch = NotificationBatch::factory()->create();
        Notification::factory()->inBatch($batch)->create();
        Sanctum::actingAs($batch->user);

        $response = $this->getJson(route('api.v1.notification-batches.show', $batch));

        $response->assertOk()->assertExactJsonStructure(['data' => [
            'id',
            'total',
            'status_counts' => ['waiting', 'scheduled', 'queued', 'processing', 'sent', 'failed', 'cancelled'],
            'is_finished',
            'created_at',
        ]]);
    });
});
