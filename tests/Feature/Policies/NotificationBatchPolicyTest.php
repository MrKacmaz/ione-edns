<?php

use App\Models\NotificationBatch;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

test('allows the owner to view and cancel the batch', function (string $ability) {
    $batch = NotificationBatch::factory()->create();

    expect(Gate::forUser($batch->user)->inspect($ability, $batch)->allowed())->toBeTrue();
})->with(['view', 'cancel']);

test('denies other users as if the batch did not exist', function (string $ability) {
    $batch = NotificationBatch::factory()->create();

    $response = Gate::forUser(User::factory()->create())->inspect($ability, $batch);

    expect($response->denied())->toBeTrue()
        ->and($response->status())->toBe(404);
})->with(['view', 'cancel']);
