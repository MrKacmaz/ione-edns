<?php

use App\Data\MetricsSnapshotData;
use App\Enums\CircuitState;
use App\Services\Observability\PrometheusFormatter;
use Carbon\CarbonImmutable;

/**
 * Build a snapshot with only the given parts filled in.
 *
 * @param  list<array<string, mixed>>  $queues
 * @param  array<string, int>  $inFlight
 * @param  list<array<string, mixed>>  $windows
 * @param  array<string, string>  $circuits
 */
function prometheusSnapshot(array $queues = [], array $inFlight = [], array $windows = [], array $circuits = []): MetricsSnapshotData
{
    return new MetricsSnapshotData(CarbonImmutable::parse('2026-11-14 12:00:00'), $queues, $inFlight, $windows, $circuits);
}

/**
 * Build the metrics of one window.
 *
 * @param  array{p50: float|null, p95: float|null, p99: float|null}  $providerLatency
 * @param  array{p50: float|null, p95: float|null, p99: float|null}  $endToEndLatency
 * @return array<string, mixed>
 */
function prometheusWindow(
    string $window,
    int $accepted,
    int $retryableFailures,
    int $permanentFailures,
    int $failed,
    ?float $successRate,
    array $providerLatency = ['p50' => null, 'p95' => null, 'p99' => null],
    array $endToEndLatency = ['p50' => null, 'p95' => null, 'p99' => null],
): array {
    return [
        'window' => $window,
        'seconds' => $window === '5m' ? 300 : 3600,
        'attempts' => [
            'total' => $accepted + $retryableFailures + $permanentFailures,
            'accepted' => $accepted,
            'retryable_failure' => $retryableFailures,
            'permanent_failure' => $permanentFailures,
        ],
        'notifications' => ['sent' => $accepted, 'failed' => $failed],
        'success_rate' => $successRate,
        'latency_ms' => ['provider' => $providerLatency, 'end_to_end' => $endToEndLatency],
    ];
}

test('renders every metric as a gauge with its help text, one sample per line', function () {
    $snapshot = prometheusSnapshot(
        queues: [['queue' => 'notifications-high', 'pending' => 3, 'delayed' => 1, 'reserved' => 2, 'oldest_pending_age_seconds' => 42]],
        inFlight: ['queued' => 7],
        windows: [prometheusWindow(
            '5m',
            accepted: 8,
            retryableFailures: 1,
            permanentFailures: 1,
            failed: 2,
            successRate: 0.8,
            providerLatency: ['p50' => 120.5, 'p95' => 300.0, 'p99' => 450.2],
            endToEndLatency: ['p50' => 2000.0, 'p95' => 3900.0, 'p99' => 3980.0],
        )],
        circuits: ['sms' => 'open'],
    );

    $exposition = (new PrometheusFormatter)->format($snapshot);

    expect($exposition)->toBe(<<<'PROMETHEUS'
        # HELP edns_queue_jobs Delivery jobs on a notification queue, by state.
        # TYPE edns_queue_jobs gauge
        edns_queue_jobs{queue="notifications-high",state="pending"} 3
        edns_queue_jobs{queue="notifications-high",state="delayed"} 1
        edns_queue_jobs{queue="notifications-high",state="reserved"} 2
        # HELP edns_queue_oldest_pending_job_age_seconds Seconds since the oldest job waiting on a notification queue was first pushed, including time it spent delayed or released back.
        # TYPE edns_queue_oldest_pending_job_age_seconds gauge
        edns_queue_oldest_pending_job_age_seconds{queue="notifications-high"} 42
        # HELP edns_notifications_in_flight Notifications that have not reached a final status, by status.
        # TYPE edns_notifications_in_flight gauge
        edns_notifications_in_flight{status="queued"} 7
        # HELP edns_delivery_attempts Provider calls in the window, by outcome.
        # TYPE edns_delivery_attempts gauge
        edns_delivery_attempts{window="5m",outcome="accepted"} 8
        edns_delivery_attempts{window="5m",outcome="retryable_failure"} 1
        edns_delivery_attempts{window="5m",outcome="permanent_failure"} 1
        # HELP edns_notifications_finished Notifications that reached sent or failed in the window.
        # TYPE edns_notifications_finished gauge
        edns_notifications_finished{window="5m",result="sent"} 8
        edns_notifications_finished{window="5m",result="failed"} 2
        # HELP edns_delivery_success_ratio Sent notifications divided by sent and failed ones in the window.
        # TYPE edns_delivery_success_ratio gauge
        edns_delivery_success_ratio{window="5m"} 0.8
        # HELP edns_delivery_latency_milliseconds Provider call and end-to-end (created to sent) latency percentiles.
        # TYPE edns_delivery_latency_milliseconds gauge
        edns_delivery_latency_milliseconds{window="5m",scope="provider",quantile="0.5"} 120.5
        edns_delivery_latency_milliseconds{window="5m",scope="provider",quantile="0.95"} 300
        edns_delivery_latency_milliseconds{window="5m",scope="provider",quantile="0.99"} 450.2
        edns_delivery_latency_milliseconds{window="5m",scope="end_to_end",quantile="0.5"} 2000
        edns_delivery_latency_milliseconds{window="5m",scope="end_to_end",quantile="0.95"} 3900
        edns_delivery_latency_milliseconds{window="5m",scope="end_to_end",quantile="0.99"} 3980
        # HELP edns_circuit_state Delivery circuit state per channel; the current state is 1, the others 0.
        # TYPE edns_circuit_state gauge
        edns_circuit_state{channel="sms",state="closed"} 0
        edns_circuit_state{channel="sms",state="open"} 1
        edns_circuit_state{channel="sms",state="half_open"} 0

        PROMETHEUS);
});

test('declares each metric once and keeps the samples of every window together under it', function () {
    $snapshot = prometheusSnapshot(windows: [
        prometheusWindow('5m', accepted: 9, retryableFailures: 0, permanentFailures: 0, failed: 1, successRate: 0.9),
        prometheusWindow('1h', accepted: 80, retryableFailures: 0, permanentFailures: 0, failed: 20, successRate: 0.8),
    ]);

    $exposition = (new PrometheusFormatter)->format($snapshot);

    expect(substr_count($exposition, '# TYPE edns_delivery_success_ratio gauge'))->toBe(1);
    expect($exposition)->toContain(
        "# TYPE edns_delivery_success_ratio gauge\n"
        ."edns_delivery_success_ratio{window=\"5m\"} 0.9\n"
        ."edns_delivery_success_ratio{window=\"1h\"} 0.8\n",
    );
});

test('leaves out samples without a value but keeps the metric declared', function () {
    $snapshot = prometheusSnapshot(
        queues: [['queue' => 'notifications-low', 'pending' => 0, 'delayed' => 0, 'reserved' => 0, 'oldest_pending_age_seconds' => null]],
        windows: [prometheusWindow('5m', accepted: 0, retryableFailures: 0, permanentFailures: 0, failed: 0, successRate: null)],
    );

    $exposition = (new PrometheusFormatter)->format($snapshot);

    expect($exposition)
        ->toContain("# TYPE edns_queue_oldest_pending_job_age_seconds gauge\n# HELP edns_notifications_in_flight")
        ->toContain("# TYPE edns_delivery_success_ratio gauge\n# HELP edns_delivery_latency_milliseconds")
        ->toContain("# TYPE edns_delivery_latency_milliseconds gauge\n# HELP edns_circuit_state");
});

test('keeps samples whose value is zero', function () {
    $snapshot = prometheusSnapshot(
        queues: [['queue' => 'notifications-low', 'pending' => 0, 'delayed' => 0, 'reserved' => 0, 'oldest_pending_age_seconds' => 0]],
        windows: [prometheusWindow('5m', accepted: 0, retryableFailures: 0, permanentFailures: 0, failed: 3, successRate: 0.0)],
    );

    $exposition = (new PrometheusFormatter)->format($snapshot);

    expect($exposition)
        ->toContain('edns_queue_jobs{queue="notifications-low",state="pending"} 0')
        ->toContain('edns_queue_oldest_pending_job_age_seconds{queue="notifications-low"} 0')
        ->toContain('edns_delivery_success_ratio{window="5m"} 0');
});

test('escapes backslashes, double quotes and line feeds in label values', function () {
    $snapshot = prometheusSnapshot(inFlight: ["odd\"status\\with\nbreak" => 1]);

    $exposition = (new PrometheusFormatter)->format($snapshot);

    expect($exposition)->toContain('edns_notifications_in_flight{status="odd\"status\\\\with\nbreak"} 1'."\n");
});

test('marks only the current circuit state of a channel with 1', function (CircuitState $current, string $samples) {
    $snapshot = prometheusSnapshot(circuits: ['push' => $current->value]);

    $exposition = (new PrometheusFormatter)->format($snapshot);

    expect($exposition)->toEndWith($samples);
})->with([
    'closed' => [CircuitState::Closed, <<<'PROMETHEUS'
        edns_circuit_state{channel="push",state="closed"} 1
        edns_circuit_state{channel="push",state="open"} 0
        edns_circuit_state{channel="push",state="half_open"} 0

        PROMETHEUS],
    'open' => [CircuitState::Open, <<<'PROMETHEUS'
        edns_circuit_state{channel="push",state="closed"} 0
        edns_circuit_state{channel="push",state="open"} 1
        edns_circuit_state{channel="push",state="half_open"} 0

        PROMETHEUS],
    'half-open' => [CircuitState::HalfOpen, <<<'PROMETHEUS'
        edns_circuit_state{channel="push",state="closed"} 0
        edns_circuit_state{channel="push",state="open"} 0
        edns_circuit_state{channel="push",state="half_open"} 1

        PROMETHEUS],
]);

test('starts a fresh exposition on every call', function () {
    $formatter = new PrometheusFormatter;
    $formatter->format(prometheusSnapshot(inFlight: ['waiting' => 5]));

    $exposition = $formatter->format(prometheusSnapshot(inFlight: ['queued' => 1]));

    expect($exposition)
        ->toContain('edns_notifications_in_flight{status="queued"} 1')
        ->not->toContain('edns_notifications_in_flight{status="waiting"}');
    expect(substr_count($exposition, '# TYPE edns_queue_jobs gauge'))->toBe(1);
});
