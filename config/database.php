<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),

    // Nome della tabella che traccia le migration eseguite.
    'migrations' => 'migrations',

    'connections' => [

        // Usato dalla suite di test (veloce, isolato, niente infrastruttura).
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', ':memory:'),
            'prefix' => '',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'export_engine'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => [],
        ],

        // Connessione dedicata allo STREAMING degli export.
        // USE_BUFFERED_QUERY=false fa sì che il driver MySQL non carichi in RAM
        // l'intero result set: con `cursor()` le righe arrivano una per volta dal
        // server. La teniamo separata dalla connessione applicativa (che resta
        // bufferizzata) perché in modalità unbuffered non si possono eseguire
        // altre query finché il cursore è aperto.
        'mysql_export' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'export_engine'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? [
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
            ] : [],
        ],
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'predis'),
        'options' => [
            'cluster' => 'redis',
            'prefix' => env('REDIS_PREFIX', 'export_engine:'),
        ],
        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD', null),
            'port' => env('REDIS_PORT', 6379),
            'database' => 0,
        ],
    ],
];
