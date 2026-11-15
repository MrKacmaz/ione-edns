<?php

use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('sends guests to the sign-in form', function () {
    $this->post(route('dashboard.notifications.store'), [...smsNotificationPayload(), 'count' => 1])
        ->assertRedirect(route('login'));

    $this->assertDatabaseCount('notifications', 0);
});

test('returns 403 to users who are not operators', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('dashboard.notifications.store'), [...smsNotificationPayload(), 'count' => 1])
        ->assertForbidden();

    $this->assertDatabaseCount('notifications', 0);
});

test('sends one copy as a single notification and says so on the dashboard', function () {
    Queue::fake([SendNotificationJob::class]);
    $operator = User::factory()->operator()->create();

    $response = $this->actingAs($operator)->post(route('dashboard.notifications.store'), [
        ...smsNotificationPayload(['content' => 'Hello [fail:503*1]', 'priority' => 'high']),
        'count' => 1,
    ]);

    $response->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('notificationsAccepted.message', 'Accepted 1 notification; follow its status below.');
    $notification = Notification::query()->sole();
    expect($notification)
        ->user_id->toBe($operator->id)
        ->batch_id->toBeNull()
        ->content->toBe('Hello [fail:503*1]')
        ->priority->value->toBe('high');
    Queue::assertPushedOn('notifications-sms-high', SendNotificationJob::class);
});

test('sends several copies as one batch', function () {
    Queue::fake([SendNotificationJob::class]);
    $operator = User::factory()->operator()->create();

    $response = $this->actingAs($operator)->post(route('dashboard.notifications.store'), [
        ...smsNotificationPayload(),
        'count' => 3,
    ]);

    $notifications = Notification::query()->get();
    $batchId = $notifications->first()->batch_id;
    $response->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('notificationsAccepted.batch_id', $batchId)
        ->assertInertiaFlash('notificationsAccepted.message', "Accepted 3 notifications as batch {$batchId}; follow their status below.");
    expect($notifications)->toHaveCount(3)
        ->and($notifications->pluck('batch_id')->unique()->all())->toBe([$batchId])
        ->and($batchId)->not->toBeNull();
    Queue::assertPushed(SendNotificationJob::class, 3);
});

test('renders the operator\'s template with the given variables', function () {
    Queue::fake([SendNotificationJob::class]);
    $operator = User::factory()->operator()->create();
    $template = NotificationTemplate::factory()->for($operator)->create(['name' => 'welcome', 'content' => 'Welcome, {{ name }}!']);

    $this->actingAs($operator)->post(route('dashboard.notifications.store'), [
        'channel' => 'sms',
        'recipient' => '+905551234567',
        'template' => 'welcome',
        'variables' => ['name' => 'Ada'],
        'count' => 1,
    ])->assertRedirect(route('dashboard'));

    expect(Notification::query()->sole())
        ->content->toBe('Welcome, Ada!')
        ->template_id->toBe($template->id);
});

test('validates the notification like the API and limits the copies to the batch size', function () {
    config(['notifications.batch.max_size' => 5]);
    $operator = User::factory()->operator()->create();

    $response = $this->actingAs($operator)->from(route('dashboard'))->post(route('dashboard.notifications.store'), [
        'channel' => 'sms',
        'recipient' => 'not-a-phone-number',
        'content' => str_repeat('a', 161),
        'count' => 6,
    ]);

    $response->assertRedirect(route('dashboard'))->assertSessionHasErrors(['recipient', 'content', 'count']);
    $this->assertDatabaseCount('notifications', 0);
});

test('neither schedules notifications nor takes idempotency keys', function () {
    Queue::fake([SendNotificationJob::class]);
    $operator = User::factory()->operator()->create();

    $this->actingAs($operator)->post(route('dashboard.notifications.store'), [
        ...smsNotificationPayload(),
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'idempotency_key' => 'dashboard-1',
        'count' => 2,
    ])->assertRedirect(route('dashboard'));

    expect(Notification::query()->get())
        ->toHaveCount(2)
        ->each(fn ($notification) => $notification->scheduled_at->toBeNull()->idempotency_key->toBeNull());
});

test('shows the new notification in the feed after the redirect', function () {
    Queue::fake([SendNotificationJob::class]);
    $operator = User::factory()->operator()->create();

    $this->actingAs($operator)
        ->followingRedirects()
        ->post(route('dashboard.notifications.store'), [...smsNotificationPayload(), 'count' => 1])
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('notifications', 1)
            ->where('notifications.0.status', 'queued')
            ->hasFlash('notificationsAccepted.message'));
});
