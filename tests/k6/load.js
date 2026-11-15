// Load test of the notification API and the delivery pipeline.
//
// Run it against the Docker Compose stack with the mock provider (no
// NOTIFICATIONS_GATEWAY_URL; a free webhook.site URL would refuse most of it):
//
//   docker compose --profile loadtest run --rm k6
//
// Two scenarios run at the same time: BATCHES batches of BATCH_SIZE
// notifications (channels and priorities mixed), and SINGLE_RATE single
// notifications a second for SINGLE_DURATION. Afterwards it waits until every
// notification is finished and reports how long the queues took to drain, and
// the busiest second per channel as the mock provider counted it, which shows
// the rate limit of 100 a second per channel holding.
//
// Every request uses the demo account, and an account may make 600 API requests
// a minute (then 429). The single notifications therefore stay at 9 a second;
// a higher SINGLE_RATE measures the API limit rather than the service.

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';

const API = __ENV.API_URL || 'http://web/api/v1';
const PROVIDER = __ENV.PROVIDER_URL || 'http://provider';
const TOKEN = __ENV.API_TOKEN || 'edns-demo-token';
const BATCHES = Number(__ENV.BATCHES || 20);
const BATCH_SIZE = Number(__ENV.BATCH_SIZE || 1000);
const SINGLE_RATE = Number(__ENV.SINGLE_RATE || 9);
const SINGLE_DURATION = __ENV.SINGLE_DURATION || '60s';
const DRAIN_TIMEOUT_SECONDS = Number(__ENV.DRAIN_TIMEOUT_SECONDS || 900);

const CHANNELS = [
    { channel: 'sms', recipient: '+905551234567' },
    { channel: 'email', recipient: 'customer@example.com' },
    { channel: 'push', recipient: 'device-token-0123456789abcdef' },
];
const PRIORITIES = ['high', 'normal', 'low'];

const accepted = new Counter('notifications_accepted');

export const options = {
    scenarios: {
        batches: {
            executor: 'per-vu-iterations',
            vus: BATCHES,
            iterations: 1,
            exec: 'sendBatch',
            maxDuration: '5m',
        },
        singles: {
            executor: 'constant-arrival-rate',
            rate: SINGLE_RATE,
            timeUnit: '1s',
            duration: SINGLE_DURATION,
            preAllocatedVUs: 50,
            maxVUs: 200,
            exec: 'sendSingle',
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        // Always met; they make k6 report the latency of each scenario on its own.
        'http_req_duration{scenario:batches}': ['max>=0'],
        'http_req_duration{scenario:singles}': ['max>=0'],
    },
    teardownTimeout: '20m',
    summaryTrendStats: ['avg', 'p(50)', 'p(95)', 'p(99)', 'max'],
};

const headers = {
    Authorization: `Bearer ${TOKEN}`,
    'Content-Type': 'application/json',
    Accept: 'application/json',
};

function notification(index) {
    const target = CHANNELS[index % CHANNELS.length];

    return {
        ...target,
        priority:
            PRIORITIES[Math.floor(index / CHANNELS.length) % PRIORITIES.length],
        content: `Load test notification ${index}`,
    };
}

export function setup() {
    http.del(`${PROVIDER}/__requests`);

    return { startedAt: Date.now() };
}

export function sendBatch() {
    const offset = (__VU - 1) * BATCH_SIZE;
    const notifications = Array.from({ length: BATCH_SIZE }, (_, i) =>
        notification(offset + i),
    );
    const response = http.post(
        `${API}/notifications/batch`,
        JSON.stringify({ notifications }),
        {
            headers,
            timeout: '60s',
        },
    );

    if (check(response, { 'batch accepted (202)': (r) => r.status === 202 })) {
        accepted.add(BATCH_SIZE);
    }
}

export function sendSingle() {
    const response = http.post(
        `${API}/notifications`,
        JSON.stringify(notification(__ITER)),
        { headers },
    );

    if (
        check(response, {
            'notification accepted (202)': (r) => r.status === 202,
        })
    ) {
        accepted.add(1);
    }
}

export function teardown(data) {
    const sentAt = Date.now();
    let inFlight = null;

    for (let waited = 0; waited < DRAIN_TIMEOUT_SECONDS; waited += 2) {
        const metrics = http.get(`${API}/metrics`, { headers });
        const counts =
            metrics.status === 200
                ? metrics.json('data.notifications_in_flight')
                : null;
        inFlight = counts
            ? Object.values(counts).reduce((total, count) => total + count, 0)
            : null;

        if (inFlight === 0) {
            break;
        }

        sleep(2);
    }

    const provider = http.get(`${PROVIDER}/__requests`).json();
    const window = http
        .get(`${API}/metrics`, { headers })
        .json('data.windows.0');

    console.log(
        JSON.stringify({
            total_seconds: Math.round((Date.now() - data.startedAt) / 100) / 10,
            drain_after_sending_seconds:
                Math.round((Date.now() - sentAt) / 100) / 10,
            unfinished: inFlight,
            provider_received: provider.received,
            provider_accepted: provider.accepted,
            provider_duplicates: provider.duplicates,
            provider_peak_per_second: provider.peak_per_second,
            last_5m: {
                success_rate: window.success_rate,
                attempts: window.attempts,
                provider_latency_ms: window.latency_ms.provider,
                end_to_end_latency_ms: window.latency_ms.end_to_end,
            },
        }),
    );
}
