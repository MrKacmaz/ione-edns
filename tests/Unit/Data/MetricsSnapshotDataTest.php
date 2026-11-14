<?php

use App\Data\MetricsSnapshotData;
use Carbon\CarbonImmutable;

test('serializes the snapshot under its API keys', function () {
    $queues = [
        ['queue' => 'notifications-high', 'pending' => 3, 'delayed' => 1, 'reserved' => 2, 'oldest_pending_age_seconds' => 12],
    ];
    $inFlight = ['waiting' => 1, 'scheduled' => 4, 'queued' => 3, 'processing' => 2];
    $windows = [[
        'window' => '1m',
        'seconds' => 60,
        'attempts' => ['total' => 10, 'accepted' => 8, 'retryable_failure' => 1, 'permanent_failure' => 1],
        'notifications' => ['sent' => 8, 'failed' => 1],
        'success_rate' => 0.8,
        'latency_ms' => [
            'provider' => ['p50' => 120.0, 'p95' => 480.0, 'p99' => 900.0],
            'end_to_end' => ['p50' => 300.0, 'p95' => 1200.0, 'p99' => 2500.0],
        ],
    ]];
    $circuits = ['sms' => 'closed', 'email' => 'open', 'push' => 'half_open'];
    $snapshot = new MetricsSnapshotData(
        generatedAt: CarbonImmutable::parse('2026-11-14T10:15:30.123456Z'),
        queues: $queues,
        inFlight: $inFlight,
        windows: $windows,
        circuits: $circuits,
    );

    $serialized = $snapshot->toArray();

    expect($serialized)->toBe([
        'generated_at' => '2026-11-14T10:15:30.123456Z',
        'queues' => $queues,
        'notifications_in_flight' => $inFlight,
        'windows' => $windows,
        'circuits' => $circuits,
    ]);
});

test('stamps the generation time in UTC with microseconds', function () {
    $snapshot = new MetricsSnapshotData(
        generatedAt: CarbonImmutable::parse('2026-11-14T13:15:30.000250+03:00'),
        queues: [],
        inFlight: [],
        windows: [],
        circuits: [],
    );

    $serialized = $snapshot->toArray();

    expect($serialized['generated_at'])->toBe('2026-11-14T10:15:30.000250Z');
});

test('rebuilds the same snapshot from its array form', function () {
    $snapshot = new MetricsSnapshotData(
        generatedAt: CarbonImmutable::parse('2026-11-14T10:15:30.123456Z'),
        queues: [['queue' => 'notifications-high', 'pending' => 3, 'delayed' => 1, 'reserved' => 2, 'oldest_pending_age_seconds' => 12]],
        inFlight: ['waiting' => 1, 'scheduled' => 4, 'queued' => 3, 'processing' => 2],
        windows: [],
        circuits: ['sms' => 'closed', 'email' => 'open', 'push' => 'half_open'],
    );

    $rebuilt = MetricsSnapshotData::fromArray($snapshot->toArray());

    expect($rebuilt->toArray())->toBe($snapshot->toArray())
        ->and($rebuilt->generatedAt->equalTo($snapshot->generatedAt))->toBeTrue();
});
