<?php

use App\Models\IdempotencyKey;
use App\Models\User;

test('prunes stored responses once they expire and keeps those still replayable', function () {
    $this->freezeSecond();
    $storedResponse = [
        'request_fingerprint' => hash('sha256', 'POST api/v1/notifications'),
        'response_status' => 202,
        'response_body' => '{"data":{"status":"queued"}}',
    ];
    $user = User::factory()->create();
    $expired = $user->idempotencyKeys()->create(['key' => 'expired-key', 'expires_at' => now()->subSecond(), ...$storedResponse]);
    $expiringNow = $user->idempotencyKeys()->create(['key' => 'expiring-key', 'expires_at' => now(), ...$storedResponse]);
    $replayable = $user->idempotencyKeys()->create(['key' => 'replayable-key', 'expires_at' => now()->addSecond(), ...$storedResponse]);

    $this->artisan('model:prune', ['--model' => [IdempotencyKey::class]])->assertSuccessful();

    $this->assertModelMissing($expired);
    $this->assertModelMissing($expiringNow);
    $this->assertModelExists($replayable);
});
