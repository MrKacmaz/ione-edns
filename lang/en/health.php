<?php

/*
|--------------------------------------------------------------------------
| Health Check Messages
|--------------------------------------------------------------------------
|
| Reasons reported by the public health endpoint. They must not reveal
| internals such as hosts or exception messages.
|
*/

return [

    'check_failed' => 'The :check check failed.',

    'horizon_inactive' => 'Horizon is not running, so no notification is being delivered.',

    'horizon_paused' => 'Horizon is paused, so no notification is being delivered.',

    'gateway_not_configured' => 'The notification gateway URL is not configured.',

    'circuits_open' => 'Deliveries are paused on these channels until their provider recovers: :channels.',

    'channels_paused' => 'Deliveries are paused on these channels by their rate limit of 0: :channels.',

    /*
    |--------------------------------------------------------------------------
    | Status Page
    |--------------------------------------------------------------------------
    |
    | Text of the HTML page that a browser gets from the health endpoint.
    |
    */

    'page' => [
        'title' => 'Service health',
        'overall' => [
            'ok' => 'All systems are working',
            'warn' => 'Working with reduced service',
            'fail' => 'The service is down',
        ],
        'overall_hint' => [
            'ok' => 'Notifications are accepted and delivered.',
            'warn' => 'Notifications are accepted, but some deliveries wait.',
            'fail' => 'A required dependency does not work. The endpoint answers 503.',
        ],
        'status' => [
            'ok' => 'OK',
            'warn' => 'Degraded',
            'fail' => 'Failing',
        ],
        'checks' => [
            'database' => ['label' => 'Database', 'description' => 'PostgreSQL answers queries.'],
            'redis' => ['label' => 'Redis', 'description' => 'Queues, rate limits and circuits are reachable.'],
            'horizon' => ['label' => 'Queue workers', 'description' => 'Horizon runs and delivers jobs.'],
            'gateway' => ['label' => 'Gateway', 'description' => 'The provider URL is configured.'],
            'delivery_circuits' => ['label' => 'Delivery circuits', 'description' => 'No channel is paused by its circuit breaker.'],
            'delivery_channels' => ['label' => 'Delivery channels', 'description' => 'No channel is paused by its rate limit.'],
        ],
        'checked_at' => 'Checked :time',
        'refresh' => 'Refreshes every :seconds seconds',
        'refresh_now' => 'Refresh now',
        'refresh_failed' => 'The last refresh failed. Retrying.',
        'links_title' => 'Tools',
        'links' => [
            'dashboard' => 'Dashboard',
            'horizon' => 'Horizon',
            'jaeger' => 'Jaeger traces',
            'api_docs' => 'API docs',
            'json' => 'Raw JSON',
        ],
    ],

];
