<?php

return [

    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => true,
        ],

        // Only used by tests / local debugging — runs jobs immediately
        // inline instead of queuing. Never use in production: this is
        // exactly the synchronous-MikroTik-call-inside-a-request problem
        // ActivateHotspotUserJob/SubscriptionService were built to avoid.
        'sync' => [
            'driver' => 'sync',
        ],
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
