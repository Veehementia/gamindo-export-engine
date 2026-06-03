<?php

return [
    // "database" di default: zero infrastruttura extra, gli export pesano poco
    // sulla coda (un job per richiesta). "redis" consigliato per alta concorrenza.
    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'exports',
            'retry_after' => (int) env('EXPORT_JOB_TIMEOUT', 3600) + 60,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'exports'),
            'retry_after' => (int) env('EXPORT_JOB_TIMEOUT', 3600) + 60,
            'block_for' => null,
        ],
    ],

    // I job falliti finiscono qui: consultabili e ri-lanciabili con `queue:retry`.
    'failed' => [
        'driver' => 'database-uuids',
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],
];
