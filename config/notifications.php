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
    | window ("base_seconds" doubling per attempt, at most "cap_seconds").
    |
    | Each channel may send at most "rate_limit_per_second" messages, spaced
    | evenly. A worker waits up to "rate_limit_max_wait_ms" for its turn and
    | otherwise hands the job back to the queue.
    |
    | Notifications stuck in "waiting" or "processing" longer than the given
    | seconds are dispatched again by the notifications:redispatch-stale command.
    |
    */

    'delivery' => [
        'max_attempts' => max(1, (int) env('NOTIFICATIONS_MAX_ATTEMPTS', 5)),

        'backoff' => [
            'base_seconds' => max(1, (int) env('NOTIFICATIONS_BACKOFF_BASE_SECONDS', 30)),
            'cap_seconds' => max(1, (int) env('NOTIFICATIONS_BACKOFF_CAP_SECONDS', 300)),
        ],

        'rate_limit_per_second' => max(1, (int) env('NOTIFICATIONS_RATE_LIMIT_PER_SECOND', 100)),
        'rate_limit_max_wait_ms' => max(0, (int) env('NOTIFICATIONS_RATE_LIMIT_MAX_WAIT_MS', 2000)),

        'stale_after_seconds' => [
            'waiting' => max(1, (int) env('NOTIFICATIONS_STALE_WAITING_SECONDS', 120)),
            'processing' => max(1, (int) env('NOTIFICATIONS_STALE_PROCESSING_SECONDS', 300)),
        ],
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

];
