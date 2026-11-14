<?php

use App\Models\NotificationTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('allows the owner to view, update and delete the template', function (string $ability) {
    $template = NotificationTemplate::factory()->create();

    expect(Gate::forUser($template->user)->inspect($ability, $template)->allowed())->toBeTrue();
})->with(['view', 'update', 'delete']);

test('denies other users as if the template did not exist', function (string $ability) {
    $template = NotificationTemplate::factory()->create();

    $response = Gate::forUser(User::factory()->create())->inspect($ability, $template);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
})->with(['view', 'update', 'delete']);
