<?php

/*
|--------------------------------------------------------------------------
| Configurazione dell'Export Engine
|--------------------------------------------------------------------------
| Centralizza i parametri operativi così da poterli tarare via .env senza
| toccare il codice (limiti anti-abuso, batch di progress, retry, ecc.).
*/

return [
    // Disco filesystem su cui salvare gli .xlsx generati.
    'disk' => env('EXPORT_DISK', 'local'),

    // Connessione DB usata per lo streaming in lettura durante l'export.
    // Su MySQL usiamo la connessione unbuffered dedicata; altrove (es. sqlite
    // nei test) usiamo la connessione di default.
    'read_connection' => env('DB_CONNECTION', 'mysql') === 'mysql' ? 'mysql_export' : null,

    // Cartella (relativa al disco) dei file generati.
    'directory' => 'exports',

    // Tetto massimo di righe per singolo export (protezione risorse).
    'max_rows' => (int) env('EXPORT_MAX_ROWS', 500000),

    // Righe restituite dall'endpoint di preview sincrona.
    'preview_rows' => (int) env('EXPORT_PREVIEW_ROWS', 100),

    // Ogni quante righe scritte aggiorniamo progress/heartbeat sul DB.
    'progress_batch' => (int) env('EXPORT_PROGRESS_BATCH', 2000),

    // Tentativi e timeout del job (retry automatico).
    'job_tries' => (int) env('EXPORT_JOB_TRIES', 3),
    'job_timeout' => (int) env('EXPORT_JOB_TIMEOUT', 3600),
];
