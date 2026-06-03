<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ingestione massiva delle anagrafiche/eventi.
 *
 * Scelte di performance:
 *  - INSERT a blocchi (chunk) invece di una INSERT per record: meno round-trip
 *    e meno overhead di transazione. Con blocchi da ~1000 righe si ingeriscono
 *    milioni di record in tempi ragionevoli.
 *  - I `payload` JSON liberi vengono serializzati una sola volta qui.
 *  - I player usano UPSERT su (version_id, external_id): l'ingestione è
 *    idempotente, si può rinviare lo stesso batch senza creare duplicati.
 */
class IngestionService
{
    private const CHUNK = 1000;

    public function ingestPlayers(int $versionId, array $players): int
    {
        $now = Carbon::now();
        $count = 0;

        foreach (array_chunk($players, self::CHUNK) as $batch) {
            $rows = [];
            foreach ($batch as $p) {
                $rows[] = [
                    'version_id' => $versionId,
                    'external_id' => (string) $p['external_id'],
                    'email' => $p['email'] ?? null,
                    'registered_at' => $this->dateOrNull($p['registered_at'] ?? null),
                    'total_score' => (int) ($p['total_score'] ?? 0),
                    'payload' => $this->json($p['payload'] ?? null),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            // UPSERT: in conflitto su (version_id, external_id) aggiorna i campi.
            DB::table('players')->upsert(
                $rows,
                ['version_id', 'external_id'],
                ['email', 'registered_at', 'total_score', 'payload', 'updated_at']
            );
            $count += count($rows);
        }

        return $count;
    }

    public function ingestEvents(int $versionId, array $events): int
    {
        return $this->bulkInsert('events', $versionId, $events, function ($e) {
            return [
                'player_id' => $e['player_id'] ?? null,
                'type' => (string) $e['type'],
                'occurred_at' => $this->dateOrNull($e['occurred_at'] ?? null),
                'payload' => $this->json($e['payload'] ?? null),
            ];
        });
    }

    public function ingestTransactions(int $versionId, array $items): int
    {
        return $this->bulkInsert('transactions', $versionId, $items, function ($t) {
            return [
                'player_id' => $t['player_id'] ?? null,
                'amount' => (int) ($t['amount'] ?? 0),
                'currency' => $t['currency'] ?? 'EUR',
                'status' => $t['status'] ?? 'completed',
                'occurred_at' => $this->dateOrNull($t['occurred_at'] ?? null),
                'payload' => $this->json($t['payload'] ?? null),
            ];
        });
    }

    public function ingestAnswers(int $versionId, array $items): int
    {
        return $this->bulkInsert('answers', $versionId, $items, function ($a) {
            return [
                'player_id' => $a['player_id'] ?? null,
                'question_id' => (string) $a['question_id'],
                'answer' => $a['answer'] ?? null,
                'is_correct' => isset($a['is_correct']) ? (bool) $a['is_correct'] : null,
                'occurred_at' => $this->dateOrNull($a['occurred_at'] ?? null),
                'payload' => $this->json($a['payload'] ?? null),
            ];
        });
    }

    public function ingestRewards(int $versionId, array $items): int
    {
        return $this->bulkInsert('rewards', $versionId, $items, function ($r) {
            return [
                'player_id' => $r['player_id'] ?? null,
                'type' => (string) $r['type'],
                'value' => $r['value'] ?? null,
                'occurred_at' => $this->dateOrNull($r['occurred_at'] ?? null),
                'payload' => $this->json($r['payload'] ?? null),
            ];
        });
    }

    /**
     * Inserimento massivo generico con mapper per-entità.
     */
    private function bulkInsert(string $table, int $versionId, array $items, callable $mapper): int
    {
        $now = Carbon::now();
        $count = 0;

        foreach (array_chunk($items, self::CHUNK) as $batch) {
            $rows = [];
            foreach ($batch as $item) {
                $rows[] = array_merge(
                    ['version_id' => $versionId],
                    $mapper($item),
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
            DB::table($table)->insert($rows);
            $count += count($rows);
        }

        return $count;
    }

    private function json($value): ?string
    {
        if ($value === null) {
            return null;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private function dateOrNull($value): ?string
    {
        if (empty($value)) {
            return null;
        }
        return Carbon::parse($value)->format('Y-m-d H:i:s');
    }
}
