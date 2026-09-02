<?php

return [

    'default' => env('CACHE_STORE', 'database'),

    'stores' => [
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        // Handy for tests — never persists anything.
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
    ],

    // Prefix keeps this app's cache keys from colliding with anything
    // else that might share the same database cache table.
    'prefix' => env('CACHE_PREFIX', 'hotspot_billing_cache'),

];
