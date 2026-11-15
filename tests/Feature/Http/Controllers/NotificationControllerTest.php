<?php

use App\Enums\NotificationStatus;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\NotificationBatch;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

describe('index', function () {
    test('returns 401 when no token is provided', function () {
        $this->getJson(route('api.v1.notifications.index'))->assertUnauthorized();
    });

    test('lists only the authenticated user\'s notifications, newest first', function () {
        $user = User::factory()->create();
        [$older, $newer] = Notification::factory()->for($user)->count(2)->sequence(
            ['created_at' => now()->subMinute()],
            ['created_at' => now()],
        )->create();
        Notification::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notifications.index'));

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    });

    test('filters notifications by status', function () {
        $user = User::factory()->create();
        $sent = Notification::factory()->for($user)->sent()->create();
        Notification::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notifications.index', ['status' => 'sent']));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sent->id);
    });

    test('filters notifications by channel', function () {
        $user = User::factory()->create();
        $email = Notification::factory()->for($user)->email()->create();
        Notification::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notifications.index', ['channel' => 'email']));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $email->id);
    });

    test('filters notifications by several statuses, given comma separated or as a list', function (array $query) {
        $user = User::factory()->create();
        $failed = Notification::factory()->for($user)->failed()->create(['created_at' => now()->subMinute()]);
        $queued = Notification::factory()->for($user)->queued()->create();
        Notification::factory()->for($user)->sent()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notifications.index', $query));

        $response->assertOk()->assertJsonPath('data.*.id', [$queued->id, $failed->id]);
    })->with([
        'comma separated' => [['status' => 'failed,queued']],
        'comma separated with spaces and a repeat' => [['status' => 'failed, queued,failed']],
        'as a list' => [['status' => ['failed', 'queued']]],
    ]);

    test('filters notifications by priority and by channel, each with several values', function () {
        $user = User::factory()->create();
        $highSms = Notification::factory()->for($user)->create(['priority' => 'high']);
        Notification::factory()->for($user)->create(['priority' => 'low']);
        $normalPush = Notification::factory()->for($user)->push()->create(['priority' => 'normal', 'created_at' => now()->subMinute()]);
        Notification::factory()->for($user)->email()->create(['priority' => 'high']);
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notifications.index', ['priority' => 'high,normal', 'channel' => 'sms,push']));

        $response->assertOk()->assertJsonPath('data.*.id', [$highSms->id, $normalPush->id]);
    });

    test('filters notifications by their exact recipient', function () {
        $user = User::factory()->create();
        $match = Notification::factory()->for($user)->create(['recipient' => '+905551234567']);
        Notification::factory()->for($user)->create(['recipient' => '+905551234568']);
        Notification::factory()->create(['recipient' => '+905551234567']);
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notifications.index', ['recipient' => '+905551234567']));

        $response->assertOk()->assertJsonPath('data.*.id', [$match->id]);
    });

    test('filters notifications by batch', function () {
        $batch = NotificationBatch::factory()->create();
        $inBatch = Notification::factory()->inBatch($batch)->create();
        Notification::factory()->for($batch->user)->create();
        Sanctum::actingAs($batch->user);

        $response = $this->getJson(route('api.v1.notifications.index', ['batch_id' => $batch->id]));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $inBatch->id);
    });

    test('filters notifications by an inclusive creation time range', function () {
        $user = User::factory()->create();
        Notification::factory()->for($user)->count(4)->sequence(
            ['created_at' => '2026-11-10 09:00:00', 'content' => 'before'],
            ['created_at' => '2026-11-11 09:00:00', 'content' => 'from'],
            ['created_at' => '2026-11-12 09:00:00', 'content' => 'to'],
            ['created_at' => '2026-11-13 09:00:00', 'content' => 'after'],
        )->create();
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notifications.index', [
            'created_from' => '2026-11-11T09:00:00Z',
            'created_to' => '2026-11-12T09:00:00Z',
        ]));

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.content', 'to')
            ->assertJsonPath('data.1.content', 'from');
    });

    test('paginates with a cursor without repeating notifications', function () {
        $user = User::factory()->create();
        $notifications = Notification::factory()->for($user)->count(3)->sequence(
            ['created_at' => now()->subMinutes(2)],
            ['created_at' => now()->subMinute()],
            ['created_at' => now()],
        )->create();
        Sanctum::actingAs($user);

        $firstPage = $this->getJson(route('api.v1.notifications.index', ['per_page' => 2]));
        $secondPage = $this->getJson(route('api.v1.notifications.index', [
            'per_page' => 2,
            'cursor' => $firstPage->json('meta.next_cursor'),
        ]));

        $firstPage->assertOk()
            ->assertJsonPath('data.*.id', [$notifications[2]->id, $notifications[1]->id]);
        $secondPage->assertOk()
            ->assertJsonPath('data.*.id', [$notifications[0]->id])
            ->assertJsonPath('meta.next_cursor', null);
    });

    test('pages through notifications created within the same second without skipping any', function (array $createdAt) {
        $user = User::factory()->create();
        Notification::factory()->for($user)->count(5)->sequence(...array_map(
            fn (string $moment): array => ['created_at' => $moment],
            $createdAt,
        ))->create();
        Sanctum::actingAs($user);

        $ids = [];
        $cursor = null;
        do {
            $page = $this->getJson(route('api.v1.notifications.index', array_filter(['per_page' => 2, 'cursor' => $cursor])));
            $ids = [...$ids, ...$page->json('data.*.id')];
            $cursor = $page->json('meta.next_cursor');
        } while ($cursor !== null);

        expect($ids)->toHaveCount(5)
            ->and(array_unique($ids))->toHaveCount(5);
    })->with([
        'apart by microseconds' => [[
            '2026-11-14 10:00:00.100000', '2026-11-14 10:00:00.200000', '2026-11-14 10:00:00.300000',
            '2026-11-14 10:00:00.400000', '2026-11-14 10:00:00.500000',
        ]],
        'at the very same moment, like a batch' => [array_fill(0, 5, '2026-11-14 10:00:00.250000')],
    ]);

    test('explains with 422 why a filter is invalid', function (array $query, string $invalidField, string $message) {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson(route('api.v1.notifications.index', $query));

        $response->assertUnprocessable()->assertJsonValidationErrors([$invalidField => $message]);
    })->with([
        'unknown status' => [['status' => 'delivered'], 'status', 'The selected status is invalid.'],
        'unknown channel' => [['channel' => 'fax'], 'channel', 'The selected channel is invalid.'],
        'one unknown status among known ones' => [['status' => 'sent,delivered'], 'status', 'The selected status is invalid.'],
        'empty status list' => [['status' => ' , '], 'status', 'The selected status is invalid.'],
        'unknown priority' => [['priority' => 'urgent'], 'priority', 'The selected priority is invalid.'],
        'recipient too long' => [['recipient' => str_repeat('1', 513)], 'recipient', 'The recipient field must not be greater than 512 characters.'],
        'batch id that is not a uuid' => [['batch_id' => '42'], 'batch_id', 'The batch id field must be a valid UUID.'],
        'start that is not a date' => [['created_from' => 'not-a-date'], 'created_from', 'The created from field must be a valid date.'],
        'end that is not a date' => [['created_to' => 'not-a-date'], 'created_to', 'The created to field must be a valid date.'],
        'start given as a relative phrase' => [
            ['created_from' => 'yesterday'],
            'created_from',
            'The created from must be an ISO 8601 date or date-time, such as 2026-11-14 or 2026-11-14T09:30:00Z.',
        ],
        'range that ends before it starts' => [
            ['created_from' => '2026-11-12', 'created_to' => '2026-11-11'],
            'created_to',
            'The created to field must be a date after or equal to created from.',
        ],
        'range that ends earlier on the same day' => [
            ['created_from' => '2026-11-12T10:00:00Z', 'created_to' => '2026-11-12T09:59:59Z'],
            'created_to',
            'The created to field must be a date after or equal to created from.',
        ],
        'page size that is not an integer' => [['per_page' => 'ten'], 'per_page', 'The per page field must be an integer.'],
        'page size below the minimum' => [['per_page' => 0], 'per_page', 'The per page field must be between 1 and 100.'],
        'page size above the maximum' => [['per_page' => 101], 'per_page', 'The per page field must be between 1 and 100.'],
        'cursor that is not a string' => [['cursor' => ['abc']], 'cursor', 'The cursor field must be a string.'],
        'cursor that was tampered with' => [
            ['cursor' => (new Cursor(['created_at' => 'not-a-date', 'id' => 'x']))->encode()],
            'cursor',
            'The cursor is invalid. Use the cursor from the links of the previous page.',
        ],
    ]);

    test('filters notifications by the end of the creation time range alone', function () {
        $user = User::factory()->create();
        Notification::factory()->for($user)->count(2)->sequence(
            ['created_at' => '2026-11-11 09:00:00', 'content' => 'before'],
            ['created_at' => '2026-11-12 09:00:01', 'content' => 'after'],
        )->create();
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notifications.index', ['created_to' => '2026-11-12T09:00:00Z']));

        $response->assertOk()->assertJsonPath('data.*.content', ['before']);
    });

    test('honours the offset of a date filter', function () {
        $user = User::factory()->create();
        Notification::factory()->for($user)->count(2)->sequence(
            ['created_at' => '2026-11-12 05:59:59', 'content' => 'before'],
            ['created_at' => '2026-11-12 06:00:00', 'content' => 'from'],
        )->create();
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notifications.index', ['created_from' => '2026-11-12T09:00:00+03:00']));

        $response->assertOk()->assertJsonPath('data.*.content', ['from']);
    });

    test('accepts an end date without a time on the day the start time falls on', function () {
        $user = User::factory()->create();
        Notification::factory()->for($user)->count(3)->sequence(
            ['created_at' => '2026-11-12 09:59:59', 'content' => 'before the start'],
            ['created_at' => '2026-11-12 18:00:00', 'content' => 'later that day'],
            ['created_at' => '2026-11-13 00:00:00', 'content' => 'next day'],
        )->create();
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notifications.index', ['created_from' => '2026-11-12T10:00:00Z', 'created_to' => '2026-11-12']));

        $response->assertOk()->assertJsonPath('data.*.content', ['later that day']);
    });

    test('includes the whole day of an end date given without a time', function () {
        $user = User::factory()->create();
        Notification::factory()->for($user)->count(2)->sequence(
            ['created_at' => '2026-11-12 23:59:59.750000', 'content' => 'last moment of the day'],
            ['created_at' => '2026-11-13 00:00:00', 'content' => 'next day'],
        )->create();
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notifications.index', ['created_to' => '2026-11-12']));

        $response->assertOk()->assertJsonPath('data.*.content', ['last moment of the day']);
    });
});

describe('store', function () {
    test('returns 401 when no token is provided', function () {
        $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload())->assertUnauthorized();

        $this->assertDatabaseCount('notifications', 0);
    });

    test('accepts a notification with normal priority, queues it and returns 202', function () {
        Queue::fake([SendNotificationJob::class]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload());

        $response->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.priority', 'normal')
            ->assertJsonPath('data.channel', 'sms')
            ->assertJsonPath('data.recipient', '+905551234567')
            ->assertJsonPath('data.batch_id', null);
        $this->assertDatabaseHas('notifications', [
            'id' => $response->json('data.id'),
            'user_id' => $user->id,
            'status' => 'queued',
            'priority' => 'normal',
            'content' => 'Your order has shipped.',
        ]);
        Queue::assertPushedOn('notifications-sms-normal', SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->notificationId === $response->json('data.id'));
    });

    test('queues a high priority notification on the high priority queue of its channel', function () {
        Queue::fake([SendNotificationJob::class]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(['priority' => 'high']));

        $response->assertAccepted();
        Queue::assertPushedOn('notifications-sms-high', SendNotificationJob::class);
    });

    test('assigns the notification to the token owner even when another user id is sent', function () {
        Queue::fake([SendNotificationJob::class]);
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(['user_id' => $otherUser->id]));

        $response->assertAccepted();
        $this->assertDatabaseHas('notifications', ['id' => $response->json('data.id'), 'user_id' => $user->id]);
        Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->notificationId === $response->json('data.id'));
    });

    test('rejects a recipient that is not addressable on the channel', function () {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(['recipient' => 'user@example.com']));

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'recipient' => 'The recipient must be an E.164 phone number (e.g. +905551234567) for the sms channel.',
        ]);
        $this->assertDatabaseCount('notifications', 0);
    });

    test('rejects content longer than the channel allows', function () {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(['content' => str_repeat('a', 161)]));

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'content' => 'The content may not be greater than 160 characters for the sms channel.',
        ]);
    });

    test('returns the existing notification with 200 when its idempotency key was already used', function () {
        $user = User::factory()->create();
        $existing = Notification::factory()->for($user)->withIdempotencyKey('order-1001-shipped')->create([
            'recipient' => '+905551234567',
            'content' => 'Your order has shipped.',
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(['idempotency_key' => 'order-1001-shipped']));

        $response->assertOk()->assertJsonPath('data.id', $existing->id);
    });

    test('returns 422 when the idempotency key was already used for a different notification', function () {
        $user = User::factory()->create();
        Notification::factory()->for($user)->withIdempotencyKey('order-1001-shipped')->create([
            'recipient' => '+905551234567',
            'content' => 'Your order has shipped.',
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload([
            'idempotency_key' => 'order-1001-shipped',
            'content' => 'A different text',
        ]));

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'idempotency_key' => 'This idempotency key was already used for a different notification.',
        ]);
        $this->assertDatabaseCount('notifications', 1);
    });

    test('creates a notification when another user already used the same idempotency key', function () {
        Queue::fake([SendNotificationJob::class]);
        Notification::factory()->withIdempotencyKey('order-1001-shipped')->create();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(['idempotency_key' => 'order-1001-shipped']));

        $response->assertAccepted();
        Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->notificationId === $response->json('data.id'));
    });

    test('renders the content from the user\'s template', function () {
        Queue::fake([SendNotificationJob::class]);
        $template = NotificationTemplate::factory()->create(['name' => 'order-shipped']);
        Sanctum::actingAs($template->user);

        $response = $this->postJson(route('api.v1.notifications.store'), [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'template' => 'order-shipped',
            'variables' => ['name' => 'Ada', 'order_id' => 1001],
        ]);

        $response->assertAccepted()
            ->assertJsonPath('data.content', 'Hi Ada, your order 1001 has shipped.')
            ->assertJsonPath('data.template_id', $template->id);
        $this->assertDatabaseHas('notifications', [
            'id' => $response->json('data.id'),
            'template_id' => $template->id,
            'content' => 'Hi Ada, your order 1001 has shipped.',
        ]);
        Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->notificationId === $response->json('data.id'));
    });

    test('rejects a notification that cannot be rendered from the template', function (array $overrides, string $attribute, string $message) {
        $user = User::factory()->create();
        NotificationTemplate::factory()->for($user)->create(['name' => 'order-shipped']);
        NotificationTemplate::factory()->create(['name' => 'someone-elses']);
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.notifications.store'), [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'template' => 'order-shipped',
            'variables' => ['name' => 'Ada', 'order_id' => 1001],
            ...$overrides,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([$attribute => $message]);
        $this->assertDatabaseCount('notifications', 0);
    })->with([
        'another user\'s template' => [
            ['template' => 'someone-elses'],
            'template',
            'The template "someone-elses" does not exist.',
        ],
        'missing variables' => [
            ['variables' => ['name' => 'Ada']],
            'variables',
            'The variables must give a text or number value to: order_id.',
        ],
    ]);

    test('holds a notification scheduled for later without queueing it', function () {
        $this->freezeSecond();
        Queue::fake([SendNotificationJob::class]);
        Sanctum::actingAs(User::factory()->create());
        $scheduledAt = now()->addHours(2);

        $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload([
            'scheduled_at' => $scheduledAt->toIso8601String(),
        ]));

        $response->assertAccepted()
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.scheduled_at', $scheduledAt->toJSON());
        $this->assertDatabaseHas('notifications', ['id' => $response->json('data.id'), 'status' => 'scheduled']);
        Queue::assertNothingPushed();
    });

    test('stores the delivery time in UTC whatever offset it was sent with', function () {
        $this->freezeSecond();
        Sanctum::actingAs(User::factory()->create());
        $scheduledAt = now()->addHours(2);

        $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload([
            'scheduled_at' => $scheduledAt->setTimezone('Europe/Istanbul')->toIso8601String(),
        ]));

        $response->assertAccepted()->assertJsonPath('data.scheduled_at', $scheduledAt->toJSON());
    });

    test('queues a notification due within the next minute, delayed until its delivery time', function () {
        $this->freezeSecond();
        Queue::fake([SendNotificationJob::class]);
        Sanctum::actingAs(User::factory()->create());
        $scheduledAt = now()->addSeconds(30);

        $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload([
            'scheduled_at' => $scheduledAt->toIso8601String(),
        ]));

        $response->assertAccepted()->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->delay instanceof DateTimeInterface
            && $job->delay->getTimestamp() === $scheduledAt->getTimestamp());
    });

    test('accepts a delivery time exactly the configured maximum ahead', function () {
        $this->freezeSecond();
        config(['notifications.schedule.max_days' => 7]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload([
            'scheduled_at' => now()->addDays(7)->toIso8601String(),
        ]));

        $response->assertAccepted()->assertJsonPath('data.status', 'scheduled');
    });

    test('explains with 422 which required fields are missing', function () {
        Queue::fake([SendNotificationJob::class]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notifications.store'), []);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'channel' => 'The channel field is required.',
            'recipient' => 'The recipient field is required.',
            'content' => 'The content field is required when template is not present.',
        ]);
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    });

    test('explains with 422 why a field is invalid', function (array $payload, string $invalidField, string $message) {
        Queue::fake([SendNotificationJob::class]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notifications.store'), $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors([$invalidField => $message]);
        $this->assertDatabaseCount('notifications', 0);
        Queue::assertNothingPushed();
    })->with([
        'unknown channel' => [smsNotificationPayload(['channel' => 'fax']), 'channel', 'The selected channel is invalid.'],
        'recipient that is not a string' => [
            smsNotificationPayload(['recipient' => 905551234567]),
            'recipient',
            'The recipient field must be a string.',
        ],
        'recipient longer than 512 characters' => [
            smsNotificationPayload(['channel' => 'push', 'recipient' => str_repeat('a', 513)]),
            'recipient',
            'The recipient field must not be greater than 512 characters.',
        ],
        'content that is not a string' => [
            smsNotificationPayload(['content' => ['Your order has shipped.']]),
            'content',
            'The content field must be a string.',
        ],
        'content together with a template' => [
            smsNotificationPayload(['template' => 'order-shipped']),
            'content',
            'The content field prohibits template / variables from being present.',
        ],
        'content together with variables' => [
            smsNotificationPayload(['variables' => ['name' => 'Ada']]),
            'content',
            'The content field prohibits template / variables from being present.',
        ],
        'template name that is not a string' => [
            ['channel' => 'sms', 'recipient' => '+905551234567', 'template' => 42],
            'template',
            'The template field must be a string.',
        ],
        'template name longer than 100 characters' => [
            ['channel' => 'sms', 'recipient' => '+905551234567', 'template' => str_repeat('a', 101)],
            'template',
            'The template field must not be greater than 100 characters.',
        ],
        'variables that are not an array' => [
            ['channel' => 'sms', 'recipient' => '+905551234567', 'template' => 'order-shipped', 'variables' => 'Ada'],
            'variables',
            'The variables field must be an array.',
        ],
        'unknown priority' => [smsNotificationPayload(['priority' => 'urgent']), 'priority', 'The selected priority is invalid.'],
        'idempotency key that is not a string' => [
            smsNotificationPayload(['idempotency_key' => 1001]),
            'idempotency_key',
            'The idempotency key field must be a string.',
        ],
        'idempotency key longer than 255 characters' => [
            smsNotificationPayload(['idempotency_key' => str_repeat('a', 256)]),
            'idempotency_key',
            'The idempotency key field must not be greater than 255 characters.',
        ],
    ]);

    test('explains with 422 why a delivery time is rejected', function (string $scheduledAt, string $message) {
        $this->travelTo('2026-11-14 09:00:00');
        config(['notifications.schedule.max_days' => 7]);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notifications.store'), smsNotificationPayload(['scheduled_at' => $scheduledAt]));

        $response->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at' => $message]);
        $this->assertDatabaseCount('notifications', 0);
    })->with([
        'not a date' => ['not-a-date', 'The scheduled at field must be a valid date.'],
        'a relative phrase' => ['tomorrow', 'The scheduled at must be an ISO 8601 date or date-time, such as 2026-11-14 or 2026-11-14T09:30:00Z.'],
        'in the past' => ['2026-11-14T08:59:00Z', 'The scheduled at field must be a date after now.'],
        'right now' => ['2026-11-14T09:00:00Z', 'The scheduled at field must be a date after now.'],
        'beyond the configured maximum' => [
            '2026-11-21T09:00:01Z',
            'The scheduled at field must be a date before or equal to 2026-11-21T09:00:00+00:00.',
        ],
    ]);
});

describe('show', function () {
    test('returns the notification to its owner', function () {
        $notification = Notification::factory()->sent()->create();
        Sanctum::actingAs($notification->user);

        $response = $this->getJson(route('api.v1.notifications.show', $notification));

        $response->assertOk()
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.status', NotificationStatus::Sent->value)
            ->assertJsonPath('data.provider_message_id', $notification->provider_message_id);
    });

    test('returns 404 for another user\'s notification', function () {
        $notification = Notification::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(route('api.v1.notifications.show', $notification))->assertNotFound();
    });

    test('returns 404 for an id that is not a uuid', function () {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/notifications/not-a-uuid')->assertNotFound();
    });

    test('returns 401 when no token is provided', function () {
        $notification = Notification::factory()->create();

        $this->getJson(route('api.v1.notifications.show', $notification))->assertUnauthorized();
    });

    test('returns exactly the fields of the notification contract', function () {
        $notification = Notification::factory()->sent()->create();
        Sanctum::actingAs($notification->user);

        $response = $this->getJson(route('api.v1.notifications.show', $notification));

        $response->assertOk()->assertExactJsonStructure(['data' => [
            'id',
            'batch_id',
            'template_id',
            'channel',
            'priority',
            'status',
            'recipient',
            'content',
            'idempotency_key',
            'correlation_id',
            'trace_id',
            'attempts',
            'provider_message_id',
            'last_error',
            'scheduled_at',
            'queued_at',
            'sent_at',
            'failed_at',
            'cancelled_at',
            'created_at',
            'updated_at',
        ]]);
    });
});
