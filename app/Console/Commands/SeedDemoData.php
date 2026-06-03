<?php

namespace App\Console\Commands;

use App\Models\Version;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Generatore di dataset demo/di test, dimensionabile da CLI.
 *
 *   php artisan demo:seed --players=1000000 --events=10000000
 *
 * Pensato per i volumi target dell'esercizio:
 *  - inserimenti in blocchi (chunk) per non saturare la RAM;
 *  - payload JSON variabili (lingue, utm, campi custom) per esercitare i filtri
 *    sui campi JSON e le aggregazioni;
 *  - niente Eloquent nel loop caldo: query builder grezzo = molto più veloce.
 */
class SeedDemoData extends Command
{
    protected $signature = 'demo:seed
        {--players=1000 : Numero di player da generare}
        {--events=10000 : Numero di eventi da generare}
        {--transactions=500 : Numero di transazioni}
        {--answers=500 : Numero di risposte}
        {--rewards=300 : Numero di premi}
        {--name= : Nome della version (default generato)}';

    protected $description = 'Genera un dataset demo (version + player + eventi + ...) per test e benchmark.';

    private const CHUNK = 2000;

    private $languages = ['it', 'en', 'es', 'fr', 'de'];
    private $countries = ['IT', 'GB', 'ES', 'FR', 'DE', 'US'];
    private $utm = ['linkedin', 'facebook', 'google', 'newsletter', 'organic', 'partner'];
    private $eventTypes = ['open', 'register', 'complete', 'answer', 'transaction', 'reward'];
    private $companies = ['Hooli', 'Umbrella', 'Globex', 'Stark', 'Wayne', 'Wonka', 'Initech'];
    private $txTypes = ['purchase', 'lead_qualified', 'reward_assigned', 'coupon_redeemed'];

    public function handle(): int
    {
        $version = Version::create([
            'name' => $this->option('name') ?: 'Demo Version ' . Carbon::now()->format('Ymd_His'),
            'game' => 'demo-game',
            'metadata' => ['seeded' => true],
        ]);
        $this->info("Version creata: #{$version->id} ({$version->name})");

        $this->seedPlayers($version->id, (int) $this->option('players'));

        [$minPlayer, $maxPlayer] = $this->playerIdRange($version->id);

        $this->seedEvents($version->id, (int) $this->option('events'), $minPlayer, $maxPlayer);
        $this->seedTransactions($version->id, (int) $this->option('transactions'), $minPlayer, $maxPlayer);
        $this->seedAnswers($version->id, (int) $this->option('answers'), $minPlayer, $maxPlayer);
        $this->seedRewards($version->id, (int) $this->option('rewards'), $minPlayer, $maxPlayer);

        $this->info("\nFatto. Usa version_id = {$version->id} per richiedere un export.");

        return 0;
    }

    private function seedPlayers(int $versionId, int $count): void
    {
        $this->line("Genero {$count} player...");
        $bar = $this->output->createProgressBar($count);
        $now = Carbon::now();

        for ($offset = 0; $offset < $count; $offset += self::CHUNK) {
            $rows = [];
            $batch = min(self::CHUNK, $count - $offset);
            for ($i = 0; $i < $batch; $i++) {
                $n = $offset + $i + 1;
                $lang = $this->pick($this->languages);
                $rows[] = [
                    'version_id' => $versionId,
                    'external_id' => 'p_' . $versionId . '_' . $n,
                    'email' => "player{$n}@example.com",
                    'registered_at' => $this->randomDate(),
                    'total_score' => mt_rand(0, 100000),
                    'payload' => json_encode([
                        'language' => $lang,
                        'country' => $this->pick($this->countries),
                        'utm_source' => $this->pick($this->utm),
                        'company' => $this->pick($this->companies),
                        'marketing_optin' => (mt_rand(0, 1) ? 'yes' : 'no'),
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('players')->insert($rows);
            $bar->advance($batch);
        }
        $bar->finish();
        $this->newLine();
    }

    private function seedEvents(int $versionId, int $count, int $minP, int $maxP): void
    {
        $this->line("Genero {$count} eventi...");
        $bar = $this->output->createProgressBar($count);
        $now = Carbon::now();

        for ($offset = 0; $offset < $count; $offset += self::CHUNK) {
            $rows = [];
            $batch = min(self::CHUNK, $count - $offset);
            for ($i = 0; $i < $batch; $i++) {
                $rows[] = [
                    'version_id' => $versionId,
                    'player_id' => mt_rand($minP, $maxP),
                    'type' => $this->pick($this->eventTypes),
                    'occurred_at' => $this->randomDate(),
                    'payload' => json_encode([
                        'score' => mt_rand(0, 1000),
                        'level' => mt_rand(1, 50),
                        'language' => $this->pick($this->languages),
                        'utm_source' => $this->pick($this->utm),
                        'custom_field_1' => 'azienda-' . mt_rand(1, 20),
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('events')->insert($rows);
            $bar->advance($batch);
        }
        $bar->finish();
        $this->newLine();
    }

    private function seedTransactions(int $versionId, int $count, int $minP, int $maxP): void
    {
        if ($count <= 0) {
            return;
        }
        $this->line("Genero {$count} transazioni...");
        $now = Carbon::now();
        for ($offset = 0; $offset < $count; $offset += self::CHUNK) {
            $rows = [];
            $batch = min(self::CHUNK, $count - $offset);
            for ($i = 0; $i < $batch; $i++) {
                $rows[] = [
                    'version_id' => $versionId,
                    'player_id' => mt_rand($minP, $maxP),
                    'amount' => mt_rand(99, 9999),
                    'currency' => 'EUR',
                    'status' => $this->pick(['completed', 'completed', 'refunded', 'failed']),
                    'occurred_at' => $this->randomDate(),
                    'payload' => json_encode([
                        'method' => $this->pick(['card', 'paypal', 'wallet']),
                        'type' => $this->pick($this->txTypes),
                        'transaction_id' => 'TX-' . mt_rand(100000, 999999),
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('transactions')->insert($rows);
        }
    }

    private function seedAnswers(int $versionId, int $count, int $minP, int $maxP): void
    {
        if ($count <= 0) {
            return;
        }
        $this->line("Genero {$count} risposte...");
        $now = Carbon::now();
        for ($offset = 0; $offset < $count; $offset += self::CHUNK) {
            $rows = [];
            $batch = min(self::CHUNK, $count - $offset);
            for ($i = 0; $i < $batch; $i++) {
                $qNum = mt_rand(1, 30);
                $rows[] = [
                    'version_id' => $versionId,
                    'player_id' => mt_rand($minP, $maxP),
                    'question_id' => 'q_' . $qNum,
                    'answer' => 'opt_' . mt_rand(1, 4),
                    'is_correct' => (bool) mt_rand(0, 1),
                    'occurred_at' => $this->randomDate(),
                    'payload' => json_encode([
                        'time_ms' => mt_rand(500, 20000),
                        'question' => 'Domanda ' . $qNum,
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('answers')->insert($rows);
        }
    }

    private function seedRewards(int $versionId, int $count, int $minP, int $maxP): void
    {
        if ($count <= 0) {
            return;
        }
        $this->line("Genero {$count} premi...");
        $now = Carbon::now();
        for ($offset = 0; $offset < $count; $offset += self::CHUNK) {
            $rows = [];
            $batch = min(self::CHUNK, $count - $offset);
            for ($i = 0; $i < $batch; $i++) {
                $rows[] = [
                    'version_id' => $versionId,
                    'player_id' => mt_rand($minP, $maxP),
                    'type' => $this->pick(['badge', 'coupon', 'points']),
                    'value' => (string) mt_rand(1, 500),
                    'occurred_at' => $this->randomDate(),
                    'payload' => json_encode(['campaign' => 'camp_' . mt_rand(1, 5)]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('rewards')->insert($rows);
        }
    }

    /** @return array{0:int,1:int} */
    private function playerIdRange(int $versionId): array
    {
        $min = (int) DB::table('players')->where('version_id', $versionId)->min('id');
        $max = (int) DB::table('players')->where('version_id', $versionId)->max('id');
        return [$min ?: 1, $max ?: 1];
    }

    private function pick(array $items)
    {
        return $items[array_rand($items)];
    }

    private function randomDate(): string
    {
        // Distribuito sul 2026, così date_from/date_to hanno effetto.
        $ts = mt_rand(strtotime('2026-01-01'), strtotime('2026-06-30'));
        return date('Y-m-d H:i:s', $ts);
    }
}
