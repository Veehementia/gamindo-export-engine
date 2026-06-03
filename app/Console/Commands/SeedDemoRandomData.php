<?php

namespace App\Console\Commands;

use App\Models\Version;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Variante "sporca" del seeder demo: genera dati volutamente INCOERENTI per
 * far emergere i controlli del foglio Data_Quality del report. È separato da
 * `demo:seed` (che resta pulito) e si lancia con `make demo_random`.
 *
 * Anomalie iniettate (in base a --anomaly-rate, default 15%):
 *  - invalid_event_order : date evento indipendenti dalla registrazione
 *                          (molti `complete` finiscono prima della registrazione)
 *  - missing_language    : eventi con payload privo di `language`
 *  - empty_payload       : eventi con payload JSON vuoto ('{}')
 *  - orphan_event        : eventi che puntano a player_id inesistenti
 *  - duplicate_player_email : alcuni player condividono la stessa email
 */
class SeedDemoRandomData extends Command
{
    protected $signature = 'demo:seed-random
        {--players=300 : Numero di player}
        {--events=4000 : Numero di eventi}
        {--transactions=200 : Numero di transazioni}
        {--answers=200 : Numero di risposte}
        {--rewards=100 : Numero di premi}
        {--anomaly-rate=15 : Percentuale di anomalie iniettate (0-100)}
        {--name= : Nome della version}';

    protected $description = 'Genera un dataset demo con anomalie casuali, per esercitare i controlli di Data Quality.';

    private const CHUNK = 2000;

    private $languages = ['it', 'en', 'es', 'fr', 'de'];
    private $utm = ['linkedin', 'google', 'direct', 'newsletter', 'partner', 'qr_event'];
    private $companies = ['Hooli', 'Umbrella', 'Globex', 'Stark', 'Wayne', 'Wonka', 'Initech'];
    private $eventTypes = ['open', 'register', 'answer', 'complete'];

    /** @var int percentuale di anomalie */
    private $rate;

    public function handle(): int
    {
        $this->rate = max(0, min(100, (int) $this->option('anomaly-rate')));

        $version = Version::create([
            'name' => $this->option('name') ?: 'Demo RANDOM ' . Carbon::now()->format('Ymd_His'),
            'game' => 'demo-game-random',
            'metadata' => ['seeded' => true, 'random' => true, 'anomaly_rate' => $this->rate],
        ]);
        $this->info("Version creata: #{$version->id} ({$version->name}) — anomalie ~{$this->rate}%");

        $this->seedPlayers($version->id, (int) $this->option('players'));
        [$minP, $maxP] = $this->playerIdRange($version->id);

        $this->seedEvents($version->id, (int) $this->option('events'), $minP, $maxP);
        $this->seedSimple('transactions', $version->id, (int) $this->option('transactions'), $minP, $maxP, function () {
            return [
                'amount' => mt_rand(99, 9999),
                'currency' => 'EUR',
                'status' => $this->pick(['completed', 'completed', 'refunded', 'failed']),
                'payload' => json_encode(['type' => $this->pick(['purchase', 'lead_qualified', 'reward_assigned'])]),
            ];
        });
        $this->seedSimple('answers', $version->id, (int) $this->option('answers'), $minP, $maxP, function () {
            $q = mt_rand(1, 10);
            return [
                'question_id' => 'q_' . $q,
                'answer' => 'opt_' . mt_rand(1, 4),
                'is_correct' => (bool) mt_rand(0, 1),
                'payload' => json_encode(['question' => 'Domanda ' . $q]),
            ];
        });
        $this->seedSimple('rewards', $version->id, (int) $this->option('rewards'), $minP, $maxP, function () {
            return [
                'type' => $this->pick(['badge', 'coupon', 'points']),
                'value' => (string) mt_rand(1, 500),
                'payload' => json_encode(['campaign' => 'camp_' . mt_rand(1, 5)]),
            ];
        });

        $this->info("\nFatto. Usa version_id = {$version->id} e genera un report per vedere le anomalie in Data_Quality.");

        return 0;
    }

    private function seedPlayers(int $versionId, int $count): void
    {
        $this->line("Genero {$count} player (con email duplicate)...");
        $now = Carbon::now();

        for ($offset = 0; $offset < $count; $offset += self::CHUNK) {
            $rows = [];
            $batch = min(self::CHUNK, $count - $offset);
            for ($i = 0; $i < $batch; $i++) {
                $n = $offset + $i + 1;
                // ANOMALIA: una quota di player condivide la stessa email.
                $email = $this->chance($this->rate) ? 'duplicate@example.com' : "user{$n}@example.test";
                $rows[] = [
                    'version_id' => $versionId,
                    'external_id' => 'pr_' . $versionId . '_' . $n,
                    'email' => $email,
                    'registered_at' => $this->randomDate(),
                    'total_score' => mt_rand(0, 100000),
                    'payload' => json_encode([
                        'language' => $this->pick($this->languages),
                        'utm_source' => $this->pick($this->utm),
                        'company' => $this->pick($this->companies),
                        'marketing_optin' => (mt_rand(0, 1) ? 'yes' : 'no'),
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('players')->insert($rows);
        }
    }

    private function seedEvents(int $versionId, int $count, int $minP, int $maxP): void
    {
        $this->line("Genero {$count} eventi (date incoerenti, payload anomali, orfani)...");
        $now = Carbon::now();

        for ($offset = 0; $offset < $count; $offset += self::CHUNK) {
            $rows = [];
            $batch = min(self::CHUNK, $count - $offset);
            for ($i = 0; $i < $batch; $i++) {
                // ANOMALIA orphan_event: punta a un player_id inesistente.
                $playerId = $this->chance($this->rate)
                    ? $maxP + mt_rand(1, 100000)
                    : mt_rand($minP, $maxP);

                // ANOMALIE payload: vuoto ('{}') oppure senza 'language'.
                $roll = mt_rand(1, 100);
                if ($roll <= $this->rate) {
                    $payload = '{}'; // empty_payload
                } elseif ($roll <= $this->rate * 2) {
                    $payload = json_encode([ // missing_language
                        'score' => mt_rand(0, 1000),
                        'utm_source' => $this->pick($this->utm),
                    ]);
                } else {
                    $payload = json_encode([
                        'score' => mt_rand(0, 1000),
                        'language' => $this->pick($this->languages),
                        'utm_source' => $this->pick($this->utm),
                    ], JSON_UNESCAPED_UNICODE);
                }

                $rows[] = [
                    'version_id' => $versionId,
                    'player_id' => $playerId,
                    'type' => $this->pick($this->eventTypes),
                    // ANOMALIA invalid_event_order: data indipendente dalla registrazione.
                    'occurred_at' => $this->randomDate(),
                    'payload' => $payload,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('events')->insert($rows);
        }
    }

    /** Inserimento generico per transactions/answers/rewards (player e data casuali). */
    private function seedSimple(string $table, int $versionId, int $count, int $minP, int $maxP, callable $extra): void
    {
        if ($count <= 0) {
            return;
        }
        $this->line("Genero {$count} {$table}...");
        $now = Carbon::now();
        for ($offset = 0; $offset < $count; $offset += self::CHUNK) {
            $rows = [];
            $batch = min(self::CHUNK, $count - $offset);
            for ($i = 0; $i < $batch; $i++) {
                $rows[] = array_merge([
                    'version_id' => $versionId,
                    'player_id' => mt_rand($minP, $maxP),
                    'occurred_at' => $this->randomDate(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $extra());
            }
            DB::table($table)->insert($rows);
        }
    }

    /** @return array{0:int,1:int} */
    private function playerIdRange(int $versionId): array
    {
        $min = (int) DB::table('players')->where('version_id', $versionId)->min('id');
        $max = (int) DB::table('players')->where('version_id', $versionId)->max('id');
        return [$min ?: 1, $max ?: 1];
    }

    private function chance(int $pct): bool
    {
        return mt_rand(1, 100) <= $pct;
    }

    private function pick(array $items)
    {
        return $items[array_rand($items)];
    }

    /** Data casuale sul 2026, indipendente da tutto (genera incoerenze). */
    private function randomDate(): string
    {
        return date('Y-m-d H:i:s', mt_rand(strtotime('2026-01-01'), strtotime('2026-06-30')));
    }
}
