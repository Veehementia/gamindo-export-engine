<?php

return [
    'default' => env('EXPORT_DISK', 'local'),

    'disks' => [
        // Disco di default: i file generati vivono in storage/app.
        // In produzione si può sostituire con 's3' senza toccare il codice
        // (l'export salva sempre via Storage::disk(config('export.disk'))).
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
        ],
    ],
];
