<?php

namespace App\Services\Observability;

use App\Data\MetricsSnapshotData;
use App\Enums\CircuitState;

/**
 * Renders a metrics snapshot in the Prometheus text exposition format.
 *
 * Window values are exported as gauges with a "window" label, since the service
 * pre-aggregates them; metrics without data in a window are left out.
 */
final class PrometheusFormatter
{
    /**
     * @var list<string>
     */
    private array $lines = [];

    public function format(MetricsSnapshotData $snapshot): string
    {
        $this->lines = [];

        $this->gauge('edns_queue_jobs', 'Delivery jobs on a notification queue, by state.');
        foreach ($snapshot->queues as $queue) {
            foreach (['pending', 'delayed', 'reserved'] as $state) {
                $this->sample('edns_queue_jobs', ['queue' => $queue['queue'], 'state' => $state], $queue[$state]);
            }
        }

        $this->gauge('edns_queue_oldest_pending_job_age_seconds', 'Age of the oldest job waiting on a notification queue.');
        foreach ($snapshot->queues as $queue) {
            $this->sample('edns_queue_oldest_pending_job_age_seconds', ['queue' => $queue['queue']], $queue['oldest_pending_age_seconds']);
        }

        $this->gauge('edns_notifications_in_flight', 'Notifications that have not reached a final status, by status.');
        foreach ($snapshot->inFlight as $status => $count) {
            $this->sample('edns_notifications_in_flight', ['status' => $status], $count);
        }

        $this->gauge('edns_delivery_attempts', 'Provider calls in the window, by outcome.');
        foreach ($snapshot->windows as $window) {
            foreach (['accepted', 'retryable_failure', 'permanent_failure'] as $outcome) {
                $this->sample('edns_delivery_attempts', ['window' => $window['window'], 'outcome' => $outcome], $window['attempts'][$outcome]);
            }
        }

        $this->gauge('edns_notifications_finished', 'Notifications that reached sent or failed in the window.');
        foreach ($snapshot->windows as $window) {
            foreach (['sent', 'failed'] as $result) {
                $this->sample('edns_notifications_finished', ['window' => $window['window'], 'result' => $result], $window['notifications'][$result]);
            }
        }

        $this->gauge('edns_delivery_success_ratio', 'Sent notifications divided by sent and failed ones in the window.');
        foreach ($snapshot->windows as $window) {
            $this->sample('edns_delivery_success_ratio', ['window' => $window['window']], $window['success_rate']);
        }

        $this->gauge('edns_delivery_latency_milliseconds', 'Provider call and end-to-end (created to sent) latency percentiles.');
        foreach ($snapshot->windows as $window) {
            foreach ($window['latency_ms'] as $scope => $percentiles) {
                foreach (['p50' => '0.5', 'p95' => '0.95', 'p99' => '0.99'] as $key => $quantile) {
                    $this->sample('edns_delivery_latency_milliseconds', [
                        'window' => $window['window'],
                        'scope' => $scope,
                        'quantile' => $quantile,
                    ], $percentiles[$key]);
                }
            }
        }

        $this->gauge('edns_circuit_state', 'Delivery circuit state per channel; the current state is 1, the others 0.');
        foreach ($snapshot->circuits as $channel => $currentState) {
            foreach (CircuitState::cases() as $state) {
                $this->sample('edns_circuit_state', ['channel' => $channel, 'state' => $state->value], $state->value === $currentState ? 1 : 0);
            }
        }

        return implode("\n", $this->lines)."\n";
    }

    private function gauge(string $name, string $help): void
    {
        $this->lines[] = "# HELP {$name} {$help}";
        $this->lines[] = "# TYPE {$name} gauge";
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function sample(string $name, array $labels, int|float|null $value): void
    {
        if ($value === null) {
            return;
        }

        $renderedLabels = implode(',', array_map(
            fn (string $label, string $labelValue): string => $label.'="'.addcslashes($labelValue, "\\\"\n").'"',
            array_keys($labels),
            array_values($labels),
        ));

        $this->lines[] = "{$name}{{$renderedLabels}} {$value}";
    }
}
