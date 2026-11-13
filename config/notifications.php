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

];
