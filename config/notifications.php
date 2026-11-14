<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Batches
    |--------------------------------------------------------------------------
    |
    | "max_size" is the largest number of notifications a single batch request
    | may contain. A larger batch is rejected as a whole.
    |
    */

    'batch' => [
        'max_size' => max(1, (int) env('NOTIFICATIONS_BATCH_MAX_SIZE', 1000)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | A notification may carry a "scheduled_at" up to "max_days" in the future.
    | Scheduled notifications wait in the database; the dispatch-due command
    | queues them shortly before their time with a matching delay.
    |
    */

    'schedule' => [
        'max_days' => max(1, (int) env('NOTIFICATIONS_SCHEDULE_MAX_DAYS', 30)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency
    |--------------------------------------------------------------------------
    |
    | Requests sent with an "Idempotency-Key" header have their successful
    | response stored so retries can replay it. "retention_hours" controls how
    | long a response stays replayable, and "lock_seconds" how long the first
    | request may hold its key before a concurrent retry can take it over.
    | Keep the lock longer than the slowest request it protects. The lock is at
    | least one second, because a zero second lock never expires.
    |
    */

    'idempotency' => [
        'retention_hours' => (int) env('NOTIFICATIONS_IDEMPOTENCY_RETENTION_HOURS', 24),
        'lock_seconds' => max(1, (int) env('NOTIFICATIONS_IDEMPOTENCY_LOCK_SECONDS', 30)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | "max_attempts" is how often the provider is tried before a notification
    | fails. Failed attempts wait a random time between zero and an exponential
    | window ("base_seconds" doubling per attempt, at most "cap_seconds"). A
    | longer Retry-After from the provider is honoured up to
    | "max_retry_after_seconds", so one odd header cannot park a notification.
    |
    | Each channel may send at most "rate_limit_per_second" messages, spaced
    | evenly. A worker waits up to "rate_limit_max_wait_ms" for its turn and
    | otherwise hands the job back to the queue.
    |
    | Notifications stuck in "waiting" or "processing" longer than the given
    | seconds are dispatched again by the notifications:redispatch-stale command.
    | So are "queued" ones whose job was lost: keep that threshold above the one
    | day a delivery job lives, so only notifications without a job match it.
    |
    */

    'delivery' => [
        'max_attempts' => max(1, (int) env('NOTIFICATIONS_MAX_ATTEMPTS', 5)),

        'backoff' => [
            'base_seconds' => max(1, (int) env('NOTIFICATIONS_BACKOFF_BASE_SECONDS', 30)),
            'cap_seconds' => max(1, (int) env('NOTIFICATIONS_BACKOFF_CAP_SECONDS', 300)),
            'max_retry_after_seconds' => max(1, (int) env('NOTIFICATIONS_BACKOFF_MAX_RETRY_AFTER_SECONDS', 3600)),
        ],

        'rate_limit_per_second' => max(1, (int) env('NOTIFICATIONS_RATE_LIMIT_PER_SECOND', 100)),
        'rate_limit_max_wait_ms' => max(0, (int) env('NOTIFICATIONS_RATE_LIMIT_MAX_WAIT_MS', 2000)),

        'stale_after_seconds' => [
            'waiting' => max(1, (int) env('NOTIFICATIONS_STALE_WAITING_SECONDS', 120)),
            'processing' => max(1, (int) env('NOTIFICATIONS_STALE_PROCESSING_SECONDS', 300)),
            'queued' => max(86_400 + 1, (int) env('NOTIFICATIONS_STALE_QUEUED_SECONDS', 90_000)),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
    |
    | Every channel's provider calls pass a circuit breaker. Once at least
    | "minimum_attempts" calls were made in the last "window_seconds" and at
    | least "failure_rate_percent" of them failed in a retryable way (timeout,
    | 5xx, 429), the circuit opens: the channel's jobs wait in the queue without
    | using up delivery attempts. After "cooldown_seconds" a single probe call
    | is let through; its success closes the circuit and its failure opens it
    | again. A probe that has not reported back within "probe_timeout_seconds"
    | is replaced by the next job, so keep it above the gateway timeout.
    |
    */

    'circuit_breaker' => [
        'window_seconds' => max(1, (int) env('NOTIFICATIONS_CIRCUIT_WINDOW_SECONDS', 60)),
        'minimum_attempts' => max(1, (int) env('NOTIFICATIONS_CIRCUIT_MINIMUM_ATTEMPTS', 20)),
        'failure_rate_percent' => min(100, max(1, (int) env('NOTIFICATIONS_CIRCUIT_FAILURE_RATE_PERCENT', 50))),
        'cooldown_seconds' => max(1, (int) env('NOTIFICATIONS_CIRCUIT_COOLDOWN_SECONDS', 30)),
        'probe_timeout_seconds' => max(1, (int) env('NOTIFICATIONS_CIRCUIT_PROBE_TIMEOUT_SECONDS', 20)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway
    |--------------------------------------------------------------------------
    |
    | The external provider every notification is handed to. The provider is
    | expected to answer with HTTP 202 and a JSON body carrying "messageId".
    |
    */

    'gateway' => [
        'url' => env('NOTIFICATIONS_GATEWAY_URL'),
        'timeout_seconds' => max(1, (int) env('NOTIFICATIONS_GATEWAY_TIMEOUT_SECONDS', 10)),
        'connect_timeout_seconds' => max(1, (int) env('NOTIFICATIONS_GATEWAY_CONNECT_TIMEOUT_SECONDS', 5)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | The metrics endpoint caches its snapshot for "cache_seconds", so frequent
    | polling by dashboards does not repeat the aggregate queries. Zero
    | disables the cache.
    |
    */

    'metrics' => [
        'cache_seconds' => max(0, (int) env('NOTIFICATIONS_METRICS_CACHE_SECONDS', 5)),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Rate Limit
    |--------------------------------------------------------------------------
    |
    | Every account may make "requests_per_minute" API requests, shared by all
    | of its tokens; further requests answer 429 with a Retry-After header.
    | This keeps one runaway client from flooding the database and queues.
    | The per-channel provider limits apply separately, at delivery.
    |
    */

    'api' => [
        'requests_per_minute' => max(1, (int) env('NOTIFICATIONS_API_REQUESTS_PER_MINUTE', 600)),
    ],

];
