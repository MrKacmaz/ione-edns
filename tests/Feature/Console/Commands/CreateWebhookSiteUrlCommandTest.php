<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('creates a webhook.site URL that answers 202 with a messageId of its own per request', function () {
    Http::fake(['webhook.site/token' => Http::response(['uuid' => '9f1c2d3e-4b5a-4c6d-8e7f-0a1b2c3d4e5f'], 201)]);

    $this->artisan('notifications:webhook-site')
        ->expectsOutputToContain('Provider URL     https://webhook.site/9f1c2d3e-4b5a-4c6d-8e7f-0a1b2c3d4e5f')
        ->expectsOutputToContain("echo 'NOTIFICATIONS_GATEWAY_URL=https://webhook.site/9f1c2d3e-4b5a-4c6d-8e7f-0a1b2c3d4e5f' >> .env")
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://webhook.site/token'
        && $request['default_status'] === 202
        && $request['default_content_type'] === 'application/json'
        && json_decode($request['default_content'], true) === [
            'messageId' => '$request.uuid$',
            'status' => 'accepted',
            'timestamp' => '$request.date$',
        ]);
});

test('fails when webhook.site does not create a URL', function () {
    Http::fake(['webhook.site/token' => Http::response(['error' => 'Too many requests'], 429)]);

    $this->artisan('notifications:webhook-site')
        ->expectsOutputToContain('webhook.site did not create a URL (HTTP 429).')
        ->assertFailed();
});

test('fails when webhook.site cannot be reached', function () {
    Http::fake(['webhook.site/token' => Http::failedConnection()]);

    $this->artisan('notifications:webhook-site')
        ->expectsOutputToContain('webhook.site could not be reached.')
        ->assertFailed();
});
