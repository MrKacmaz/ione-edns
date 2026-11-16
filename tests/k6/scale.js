// Scale test: send TOTAL notifications in batches, then wait until all are delivered.
//
// One run is one scenario of the series that tests/k6/scale.sh runs (100 up to
// 1,000,000 notifications). Run it against the Docker Compose stack with the
// mock provider:
//
//   docker compose --profile loadtest run --rm k6 run -e TOTAL=10000 /scripts/scale.js
//
// CONCURRENCY batch requests run at the same time. The notifications are spread
// evenly over the three channels and three priorities, and their content starts
// with "Scale <TOTAL>-", so a run's notifications can be found in the database.
// A 429 from the API's limit per account is retried after its Retry-After.
//
// The last line of the output is a JSON summary: how long accepting and
// delivering took, the batch latency, and the mock provider's counters, which
// show whether every notification arrived exactly once.

import http from 'k6/http';
import { check, sleep } from 'k6';
import exec from 'k6/execution';
import { Counter, Trend } from 'k6/metrics';

const API = __ENV.API_URL || 'http://web/api/v1';
const PROVIDER = __ENV.PROVIDER_URL || 'http://provider';
const TOKEN = __ENV.API_TOKEN || 'edns-demo-token';
const TOTAL = Number(__ENV.TOTAL || 1000);
const BATCH_SIZE = Math.min(Number(__ENV.BATCH_SIZE || 1000), TOTAL);
const CONCURRENCY = Number(__ENV.CONCURRENCY || 20);
const BATCHES = Math.ceil(TOTAL / BATCH_SIZE);
// Delivery runs at about 300 a second (100 per channel), so allow twice that time.
const DRAIN_TIMEOUT_SECONDS = Number(
    __ENV.DRAIN_TIMEOUT_SECONDS || 300 + Math.ceil(TOTAL / 150),
);

const CHANNELS = [
    { channel: 'sms', recipient: '+905551234567' },
    { channel: 'email', recipient: 'customer@example.com' },
    { channel: 'push', recipient: 'device-token-0123456789abcdef' },
];
const PRIORITIES = ['high', 'normal', 'low'];

const accepted = new Counter('notifications_accepted');
const throttledRequests = new Counter('api_requests_throttled');
const batchDuration = new Trend('batch_duration', true);

export const options = {
    scenarios: {
        batches: {
            executor: 'shared-iterations',
            vus: Math.min(CONCURRENCY, BATCHES),
            iterations: BATCHES,
            maxDuration: '3h',
        },
    },
    teardownTimeout: '3h',
    summaryTrendStats: ['p(50)', 'p(95)', 'p(99)', 'max'],
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
        content: `Scale ${TOTAL}-${index}`,
    };
}

export function setup() {
    http.del(`${PROVIDER}/__requests`);

    return { startedAt: Date.now() };
}

export default function () {
    const batch = exec.scenario.iterationInTest;
    const offset = batch * BATCH_SIZE;
    const size = Math.min(BATCH_SIZE, TOTAL - offset);
    const body = JSON.stringify({
        notifications: Array.from({ length: size }, (_, i) =>
            notification(offset + i),
        ),
    });

    for (let attempt = 1; attempt <= 20; attempt++) {
        const response = http.post(`${API}/notifications/batch`, body, {
            headers,
            timeout: '120s',
        });

        if (response.status === 429) {
            throttledRequests.add(1);
            sleep(Number(response.headers['Retry-After'] || 1));
            continue;
        }

        batchDuration.add(response.timings.duration);

        if (
            check(response, {
                'batch accepted (202)': (r) => r.status === 202,
            })
        ) {
            accepted.add(size);
        }

        return;
    }
}

export function teardown(data) {
    const acceptedAt = Date.now();
    let provider = null;
    let inFlight = null;

    for (let waited = 0; waited < DRAIN_TIMEOUT_SECONDS; waited += 2) {
        provider = http.get(`${PROVIDER}/__requests`).json();
        const metrics = http.get(`${API}/metrics`, { headers });
        const counts =
            metrics.status === 200
                ? metrics.json('data.notifications_in_flight')
                : null;
        inFlight = counts
            ? Object.values(counts).reduce((total, count) => total + count, 0)
            : null;

        if (provider.accepted >= TOTAL && inFlight === 0) {
            break;
        }

        sleep(2);
    }

    console.log(
        'SCALE_RESULT ' +
            JSON.stringify({
                total: TOTAL,
                batches: BATCHES,
                concurrency: Math.min(CONCURRENCY, BATCHES),
                accept_seconds: (acceptedAt - data.startedAt) / 1000,
                total_seconds: (Date.now() - data.startedAt) / 1000,
                unfinished: inFlight,
                provider_received: provider.received,
                provider_accepted: provider.accepted,
                provider_duplicates: provider.duplicates,
                provider_failed: provider.failed,
                provider_peak_per_second: provider.peak_per_second,
            }),
    );
}
