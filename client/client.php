<?php

/*
|--------------------------------------------------------------------------
| Client di integrazione (Bonus: scenario Client <-> Server)
|--------------------------------------------------------------------------
| Dimostra l'intero flusso end-to-end via HTTP, esattamente come farebbe un
| sistema esterno che si integra con l'Export Engine:
|
|   1. crea una version
|   2. ingerisce player ed eventi (batch)
|   3. richiede un export con più fogli (righe + aggregazione)
|   4. fa polling dello stato finché è "completed" (mostrando il progress)
|   5. scarica il file .xlsx generato
|
| Uso:
|   php client/client.php [base_url]
|   (default base_url: http://localhost:8080)
*/

$base = rtrim($argv[1] ?? 'http://localhost:8080', '/') . '/api/v1';

function call(string $method, string $url, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Errore di connessione (host irraggiungibile, porta sbagliata, server giù):
    // meglio fermarsi subito con un messaggio chiaro che ciclare a vuoto.
    if ($raw === false || $status === 0) {
        fwrite(STDERR, "ERRORE di connessione verso $url: " . ($curlError ?: 'nessuna risposta') . "\n");
        fwrite(STDERR, "Suggerimento: dentro Docker usa la base URL 'http://nginx' (non localhost).\n");
        exit(1);
    }
    if ($status >= 400) {
        fwrite(STDERR, "ERRORE HTTP $status su $url:\n$raw\n");
        exit(1);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "Risposta non-JSON da $url (HTTP $status):\n$raw\n");
        exit(1);
    }
    return $decoded;
}

echo "==> 1. Creo la version\n";
$version = call('POST', "$base/versions", ['name' => 'Client Demo ' . date('H:i:s'), 'game' => 'demo']);
$versionId = $version['id'];
echo "    version_id = $versionId\n";

echo "==> 2a. Ingerisco 1.000 player\n";
$players = [];
for ($i = 1; $i <= 1000; $i++) {
    $players[] = [
        'external_id' => "p$i",
        'email' => "player$i@example.com",
        'registered_at' => sprintf('2026-01-%02d', random_int(1, 28)),
        'total_score' => random_int(0, 5000),
        'payload' => ['language' => ['it', 'en', 'es'][array_rand(['it', 'en', 'es'])]],
    ];
}
$res = call('POST', "$base/versions/$versionId/players", ['players' => $players]);
echo "    inseriti {$res['inserted']} player\n";

echo "==> 2b. Ingerisco 5.000 eventi\n";
$events = [];
$types = ['open', 'register', 'complete', 'answer'];
for ($i = 1; $i <= 5000; $i++) {
    $events[] = [
        'player_id' => random_int(1, 1000),
        'type' => $types[array_rand($types)],
        'occurred_at' => sprintf('2026-01-%02d 12:00:00', random_int(1, 28)),
        'payload' => [
            'score' => random_int(0, 1000),
            'language' => ['it', 'en', 'es'][array_rand(['it', 'en', 'es'])],
            'utm_source' => ['linkedin', 'google', 'organic'][array_rand(['linkedin', 'google', 'organic'])],
        ],
    ];
}
$res = call('POST', "$base/versions/$versionId/events", ['events' => $events]);
echo "    inseriti {$res['inserted']} eventi\n";

echo "==> 3. Richiedo l'export (foglio righe + foglio aggregato)\n";
$export = call('POST', "$base/versions/$versionId/exports", [
    'format' => 'xlsx',
    'date_from' => '2026-01-01',
    'date_to' => '2026-01-31',
    'sheets' => [
        [
            'name' => 'players',
            'columns' => ['player_id', 'email', 'registered_at', 'total_score', 'payload.language'],
            'filters' => ['payload.language' => 'it'],
            'sort' => ['registered_at:desc'],
        ],
        [
            'name' => 'events_summary',
            'group_by' => ['type', 'payload.language'],
            'metrics' => ['count', 'unique_players'],
        ],
    ],
]);
$exportId = $export['id'];
echo "    export_id = $exportId (stato: {$export['status']})\n";

echo "==> 4. Polling dello stato\n";
do {
    usleep(500000); // 0.5s
    $status = call('GET', "$base/exports/$exportId");
    echo "    stato={$status['status']} progress={$status['progress']}% righe={$status['rows_written']}/{$status['rows_estimated']}\n";
} while (!in_array($status['status'], ['completed', 'failed', 'cancelled'], true));

if ($status['status'] !== 'completed') {
    fwrite(STDERR, "Export non completato: {$status['status']} ({$status['error']})\n");
    exit(1);
}

echo "==> 5. Scarico il file\n";
$out = __DIR__ . '/downloaded_export.xlsx';
file_put_contents($out, file_get_contents("$base/exports/$exportId/download"));
echo "    salvato in $out (" . filesize($out) . " byte)\n";
echo "FATTO ✅\n";
