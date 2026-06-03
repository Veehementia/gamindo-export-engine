<?php

namespace Database\Seeders;

use App\Models\Version;
use App\Services\IngestionService;
use Illuminate\Database\Seeder;

/**
 * Seed di base: una version con un piccolo dataset coerente, utile per provare
 * subito gli endpoint senza generare milioni di righe (per quello: demo:seed).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        /** @var IngestionService $ingest */
        $ingest = app(IngestionService::class);

        $version = Version::create([
            'name' => 'Sample Version',
            'game' => 'sample-game',
            'metadata' => ['env' => 'seed'],
        ]);

        $ingest->ingestPlayers($version->id, [
            ['external_id' => 'p1', 'email' => 'mario@example.com', 'registered_at' => '2026-01-05', 'total_score' => 1200, 'payload' => ['language' => 'it']],
            ['external_id' => 'p2', 'email' => 'jane@example.com', 'registered_at' => '2026-01-08', 'total_score' => 800, 'payload' => ['language' => 'en']],
            ['external_id' => 'p3', 'email' => 'luca@example.com', 'registered_at' => '2026-02-01', 'total_score' => 300, 'payload' => ['language' => 'it']],
        ]);

        $ingest->ingestEvents($version->id, [
            ['player_id' => 1, 'type' => 'open', 'occurred_at' => '2026-01-05 10:00:00', 'payload' => ['language' => 'it', 'score' => 10]],
            ['player_id' => 1, 'type' => 'complete', 'occurred_at' => '2026-01-06 11:00:00', 'payload' => ['language' => 'it', 'score' => 90]],
            ['player_id' => 2, 'type' => 'open', 'occurred_at' => '2026-01-08 09:00:00', 'payload' => ['language' => 'en', 'score' => 20]],
        ]);

        $this->command->info("Seed completato. version_id = {$version->id}");
    }
}
