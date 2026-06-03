<?php

/*
|--------------------------------------------------------------------------
| Entry point HTTP
|--------------------------------------------------------------------------
| Tutte le richieste passano da qui (nginx -> public/index.php). Carichiamo
| l'autoloader di Composer, creiamo l'applicazione Lumen e la mandiamo in
| esecuzione sulla richiesta corrente.
*/

$app = require __DIR__ . '/../bootstrap/app.php';

$app->run();
