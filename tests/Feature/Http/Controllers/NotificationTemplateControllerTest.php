<?php

use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Pagination\Cursor;
use Laravel\Sanctum\Sanctum;

describe('index', function () {
    test('returns 401 when no token is provided', function () {
        $this->getJson(route('api.v1.notification-templates.index'))->assertUnauthorized();
    });

    test('lists only the authenticated user\'s templates by name', function () {
        $user = User::factory()->create();
        NotificationTemplate::factory()->for($user)->count(2)->sequence(['name' => 'welcome'], ['name' => 'order-shipped'])->create();
        NotificationTemplate::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notification-templates.index'));

        $response->assertOk()->assertJsonPath('data.*.name', ['order-shipped', 'welcome']);
    });

    test('filters templates by channel', function () {
        $user = User::factory()->create();
        $email = NotificationTemplate::factory()->for($user)->email()->create();
        NotificationTemplate::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $response = $this->getJson(route('api.v1.notification-templates.index', ['channel' => 'email']));

        $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $email->id);
    });

    test('paginates with a cursor without repeating templates', function () {
        $user = User::factory()->create();
        NotificationTemplate::factory()->for($user)->count(3)->sequence(['name' => 'alpha'], ['name' => 'bravo'], ['name' => 'charlie'])->create();
        Sanctum::actingAs($user);

        $firstPage = $this->getJson(route('api.v1.notification-templates.index', ['per_page' => 2]));
        $secondPage = $this->getJson(route('api.v1.notification-templates.index', [
            'per_page' => 2,
            'cursor' => $firstPage->json('meta.next_cursor'),
        ]));

        $firstPage->assertOk()->assertJsonPath('data.*.name', ['alpha', 'bravo']);
        $secondPage->assertOk()
            ->assertJsonPath('data.*.name', ['charlie'])
            ->assertJsonPath('meta.next_cursor', null);
    });

    test('explains with 422 why a filter is invalid', function (array $query, string $invalidField, string $message) {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson(route('api.v1.notification-templates.index', $query));

        $response->assertUnprocessable()->assertJsonValidationErrors([$invalidField => $message]);
    })->with([
        'unknown channel' => [['channel' => 'fax'], 'channel', 'The selected channel is invalid.'],
        'page size that is not an integer' => [['per_page' => 'ten'], 'per_page', 'The per page field must be an integer.'],
        'page size below the minimum' => [['per_page' => 0], 'per_page', 'The per page field must be between 1 and 100.'],
        'page size above the maximum' => [['per_page' => 101], 'per_page', 'The per page field must be between 1 and 100.'],
        'cursor that is not a string' => [['cursor' => ['abc']], 'cursor', 'The cursor field must be a string.'],
        'cursor that was tampered with' => [
            ['cursor' => (new Cursor(['name' => 'order-shipped', 'id' => 'x']))->encode()],
            'cursor',
            'The cursor is invalid. Use the cursor from the links of the previous page.',
        ],
    ]);
});

describe('store', function () {
    test('returns 401 when no token is provided', function () {
        $this->postJson(route('api.v1.notification-templates.store'), [])->assertUnauthorized();
    });

    test('creates a template and lists the variables it needs', function () {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.notification-templates.store'), [
            'name' => 'order-shipped',
            'channel' => 'sms',
            'content' => 'Hi {{ name }}, order {{order_id}} is on its way. Thanks, {{ name }}!',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'order-shipped')
            ->assertJsonPath('data.channel', 'sms')
            ->assertJsonPath('data.variables', ['name', 'order_id']);
        $this->assertDatabaseHas('notification_templates', ['id' => $response->json('data.id'), 'user_id' => $user->id]);
    });

    test('accepts a name another user already uses', function () {
        NotificationTemplate::factory()->create(['name' => 'order-shipped']);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notification-templates.store'), [
            'name' => 'order-shipped',
            'channel' => 'sms',
            'content' => 'Your order has shipped.',
        ]);

        $response->assertCreated();
    });

    test('rejects content with a malformed placeholder', function () {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notification-templates.store'), [
            'name' => 'order-shipped',
            'channel' => 'sms',
            'content' => 'Hi {{ first name }}',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'content' => 'The content may only use placeholders like {{ name }}, whose names consist of letters, digits and underscores.',
        ]);
    });

    test('rejects content whose fixed text alone exceeds the channel limit', function () {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notification-templates.store'), [
            'name' => 'order-shipped',
            'channel' => 'sms',
            'content' => str_repeat('a', 161).'{{ name }}',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'content' => 'The content may not be greater than 160 characters for the sms channel, not counting its placeholders.',
        ]);
    });

    test('does not count placeholders against the channel limit', function () {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notification-templates.store'), [
            'name' => 'order-shipped',
            'channel' => 'sms',
            'content' => str_repeat('a', 150).'{{ a_rather_long_variable_name }}',
        ]);

        $response->assertCreated();
    });

    test('explains with 422 which required fields are missing', function () {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notification-templates.store'), []);

        $response->assertUnprocessable()->assertJsonValidationErrors([
            'name' => 'The name field is required.',
            'channel' => 'The channel field is required.',
            'content' => 'The content field is required.',
        ]);
        $this->assertDatabaseCount('notification_templates', 0);
    });

    test('explains with 422 why a field is invalid', function (array $overrides, string $invalidField, string $message) {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson(route('api.v1.notification-templates.store'), [
            'name' => 'order-shipped',
            'channel' => 'sms',
            'content' => 'Your order has shipped.',
            ...$overrides,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors([$invalidField => $message]);
        $this->assertDatabaseCount('notification_templates', 0);
    })->with([
        'name that is not a string' => [['name' => 42], 'name', 'The name field must be a string.'],
        'name longer than 100 characters' => [['name' => str_repeat('a', 101)], 'name', 'The name field must not be greater than 100 characters.'],
        'name with spaces and punctuation' => [
            ['name' => 'order shipped!'],
            'name',
            'The name field must only contain letters, numbers, dashes, and underscores.',
        ],
        'name with letters outside ASCII' => [
            ['name' => 'sipariş-gönderildi'],
            'name',
            'The name field must only contain letters, numbers, dashes, and underscores.',
        ],
        'unknown channel' => [['channel' => 'fax'], 'channel', 'The selected channel is invalid.'],
        'content that is not a string' => [['content' => ['Your order has shipped.']], 'content', 'The content field must be a string.'],
    ]);

    test('explains with 422 that the user already uses the name', function () {
        $user = User::factory()->create();
        NotificationTemplate::factory()->for($user)->create(['name' => 'order-shipped']);
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.notification-templates.store'), [
            'name' => 'order-shipped',
            'channel' => 'sms',
            'content' => 'Your order has shipped.',
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['name' => 'The name has already been taken.']);
        $this->assertDatabaseCount('notification_templates', 1);
    });

    test('assigns the template to the token owner even when another user id is sent', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson(route('api.v1.notification-templates.store'), [
            'name' => 'order-shipped',
            'channel' => 'sms',
            'content' => 'Your order has shipped.',
            'user_id' => $otherUser->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('notification_templates', ['id' => $response->json('data.id'), 'user_id' => $user->id]);
    });
});

describe('show', function () {
    test('shows the user\'s template', function () {
        $template = NotificationTemplate::factory()->create();
        Sanctum::actingAs($template->user);

        $response = $this->getJson(route('api.v1.notification-templates.show', $template));

        $response->assertOk()
            ->assertJsonPath('data.id', $template->id)
            ->assertJsonPath('data.variables', ['name', 'order_id']);
    });

    test('returns 404 for another user\'s template', function () {
        $template = NotificationTemplate::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(route('api.v1.notification-templates.show', $template))->assertNotFound();
    });

    test('returns 401 when no token is provided', function () {
        $template = NotificationTemplate::factory()->create();

        $this->getJson(route('api.v1.notification-templates.show', $template))->assertUnauthorized();
    });

    test('returns 404 for an id that is not a uuid', function () {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson(route('api.v1.notification-templates.show', 'not-a-uuid'))->assertNotFound();
    });

    test('returns exactly the fields of the template contract', function () {
        $template = NotificationTemplate::factory()->create();
        Sanctum::actingAs($template->user);

        $response = $this->getJson(route('api.v1.notification-templates.show', $template));

        $response->assertOk()->assertExactJsonStructure(['data' => [
            'id',
            'name',
            'channel',
            'content',
            'variables',
            'created_at',
            'updated_at',
        ]]);
    });
});

describe('update', function () {
    test('changes the name and content', function () {
        $template = NotificationTemplate::factory()->create();
        Sanctum::actingAs($template->user);

        $response = $this->patchJson(route('api.v1.notification-templates.update', $template), [
            'name' => 'order-dispatched',
            'content' => 'Order {{ order_id }} left our warehouse.',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'order-dispatched')
            ->assertJsonPath('data.variables', ['order_id']);
        expect($template->refresh()->content)->toBe('Order {{ order_id }} left our warehouse.');
    });

    test('accepts the name the template already has without reporting it as taken', function () {
        $template = NotificationTemplate::factory()->create(['name' => 'order-shipped']);
        Sanctum::actingAs($template->user);

        $response = $this->patchJson(route('api.v1.notification-templates.update', $template), [
            'name' => 'order-shipped',
            'content' => 'Your order has shipped.',
        ]);

        $response->assertOk()->assertJsonPath('data.name', 'order-shipped');
    });

    test('returns 404 and leaves another user\'s template untouched', function () {
        $template = NotificationTemplate::factory()->create(['name' => 'order-shipped']);
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson(route('api.v1.notification-templates.update', $template), ['name' => 'taken-over'])->assertNotFound();

        expect($template->refresh()->name)->toBe('order-shipped');
    });

    test('returns 401 and leaves the template untouched when no token is provided', function () {
        $template = NotificationTemplate::factory()->create(['name' => 'order-shipped']);

        $this->patchJson(route('api.v1.notification-templates.update', $template), ['name' => 'taken-over'])->assertUnauthorized();

        $this->assertDatabaseHas('notification_templates', ['id' => $template->id, 'name' => 'order-shipped']);
    });

    test('returns 404 for an id that is not a uuid', function () {
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson(route('api.v1.notification-templates.update', 'not-a-uuid'), ['name' => 'renamed'])->assertNotFound();
    });

    test('returns 404 rather than validation errors for another user\'s template', function () {
        $template = NotificationTemplate::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $response = $this->patchJson(route('api.v1.notification-templates.update', $template), [
            'name' => 'not a valid name!',
            'channel' => 'email',
        ]);

        $response->assertNotFound()->assertJsonMissingPath('errors');
    });

    test('explains with 422 why a change is invalid', function (array $changes, string $invalidField, string $message) {
        $template = NotificationTemplate::factory()->create(['name' => 'order-shipped']);
        Sanctum::actingAs($template->user);

        $response = $this->patchJson(route('api.v1.notification-templates.update', $template), $changes);

        $response->assertUnprocessable()->assertJsonValidationErrors([$invalidField => $message]);
        $this->assertDatabaseHas('notification_templates', [
            'id' => $template->id,
            'name' => 'order-shipped',
            'channel' => 'sms',
            'content' => 'Hi {{ name }}, your order {{ order_id }} has shipped.',
        ]);
    })->with([
        'name that is not a string' => [['name' => 42], 'name', 'The name field must be a string.'],
        'name longer than 100 characters' => [['name' => str_repeat('a', 101)], 'name', 'The name field must not be greater than 100 characters.'],
        'name with spaces and punctuation' => [
            ['name' => 'order shipped!'],
            'name',
            'The name field must only contain letters, numbers, dashes, and underscores.',
        ],
        'name with letters outside ASCII' => [
            ['name' => 'sipariş-gönderildi'],
            'name',
            'The name field must only contain letters, numbers, dashes, and underscores.',
        ],
        'change of channel' => [['channel' => 'email'], 'channel', 'The channel field is prohibited.'],
        'content that is not a string' => [['content' => ['Your order has shipped.']], 'content', 'The content field must be a string.'],
        'content too long for the template\'s own channel' => [
            ['content' => str_repeat('a', 161)],
            'content',
            'The content may not be greater than 160 characters for the sms channel, not counting its placeholders.',
        ],
    ]);

    test('explains with 422 that the user already uses the name for another template', function () {
        $user = User::factory()->create();
        NotificationTemplate::factory()->for($user)->create(['name' => 'welcome']);
        $template = NotificationTemplate::factory()->for($user)->create(['name' => 'order-shipped']);
        Sanctum::actingAs($user);

        $response = $this->patchJson(route('api.v1.notification-templates.update', $template), ['name' => 'welcome']);

        $response->assertUnprocessable()->assertJsonValidationErrors(['name' => 'The name has already been taken.']);
        $this->assertDatabaseHas('notification_templates', ['id' => $template->id, 'name' => 'order-shipped']);
    });

    test('accepts a name another user already uses', function () {
        NotificationTemplate::factory()->create(['name' => 'welcome']);
        $template = NotificationTemplate::factory()->create(['name' => 'order-shipped']);
        Sanctum::actingAs($template->user);

        $response = $this->patchJson(route('api.v1.notification-templates.update', $template), ['name' => 'welcome']);

        $response->assertOk()->assertJsonPath('data.name', 'welcome');
        $this->assertDatabaseHas('notification_templates', ['id' => $template->id, 'name' => 'welcome']);
    });

    test('keeps the template with its owner even when another user id is sent', function () {
        $template = NotificationTemplate::factory()->create();
        $otherUser = User::factory()->create();
        Sanctum::actingAs($template->user);

        $response = $this->patchJson(route('api.v1.notification-templates.update', $template), [
            'name' => 'order-dispatched',
            'user_id' => $otherUser->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('notification_templates', [
            'id' => $template->id,
            'name' => 'order-dispatched',
            'user_id' => $template->user_id,
        ]);
    });
});

describe('destroy', function () {
    test('deletes the template while its notifications keep their content', function () {
        $template = NotificationTemplate::factory()->create();
        $notification = Notification::factory()->for($template->user)->create([
            'template_id' => $template->id,
            'content' => 'Hi Ada, your order 42 has shipped.',
        ]);
        Sanctum::actingAs($template->user);

        $this->deleteJson(route('api.v1.notification-templates.destroy', $template))->assertNoContent();

        $this->assertModelMissing($template);
        expect($notification->refresh())
            ->template_id->toBeNull()
            ->content->toBe('Hi Ada, your order 42 has shipped.');
    });

    test('returns 404 and keeps another user\'s template', function () {
        $template = NotificationTemplate::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson(route('api.v1.notification-templates.destroy', $template))->assertNotFound();

        $this->assertModelExists($template);
    });

    test('returns 401 and keeps the template when no token is provided', function () {
        $template = NotificationTemplate::factory()->create();

        $this->deleteJson(route('api.v1.notification-templates.destroy', $template))->assertUnauthorized();

        $this->assertModelExists($template);
    });

    test('returns 404 for an id that is not a uuid', function () {
        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson(route('api.v1.notification-templates.destroy', 'not-a-uuid'))->assertNotFound();
    });
});
