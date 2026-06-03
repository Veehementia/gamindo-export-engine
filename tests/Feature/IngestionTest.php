<?php

namespace Tests\Feature;

use App\Models\Version;
use Illuminate\Support\Facades\DB;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class IngestionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_can_create_a_version(): void
    {
        $this->json('POST', '/api/v1/versions', ['name' => 'Campagna Q1', 'game' => 'quiz']);

        $this->seeStatusCode(201);
        $this->seeInDatabase('versions', ['name' => 'Campagna Q1']);
    }

    public function test_can_ingest_players_idempotently(): void
    {
        $version = Version::create(['name' => 'V1']);

        $payload = ['players' => [
            ['external_id' => 'p1', 'email' => 'a@x.it', 'total_score' => 10, 'payload' => ['language' => 'it']],
            ['external_id' => 'p2', 'email' => 'b@x.it', 'total_score' => 20],
        ]];

        $this->json('POST', "/api/v1/versions/{$version->id}/players", $payload);
        $this->seeStatusCode(201);

        // Re-invio lo stesso batch: l'UPSERT non crea duplicati.
        $this->json('POST', "/api/v1/versions/{$version->id}/players", $payload);

        $this->assertEquals(2, DB::table('players')->where('version_id', $version->id)->count());
    }

    public function test_can_ingest_events_with_free_json_payload(): void
    {
        $version = Version::create(['name' => 'V1']);

        $this->json('POST', "/api/v1/versions/{$version->id}/events", ['events' => [
            ['player_id' => 1, 'type' => 'open', 'occurred_at' => '2026-01-01 10:00:00', 'payload' => ['score' => 5, 'language' => 'it']],
            ['player_id' => 1, 'type' => 'complete', 'occurred_at' => '2026-01-02 10:00:00', 'payload' => ['score' => 99, 'language' => 'it']],
        ]]);

        $this->seeStatusCode(201);
        $this->assertEquals(2, DB::table('events')->where('version_id', $version->id)->count());
    }

    public function test_ingestion_validates_required_fields(): void
    {
        $version = Version::create(['name' => 'V1']);

        $this->json('POST', "/api/v1/versions/{$version->id}/events", ['events' => [['payload' => []]]]);

        $this->seeStatusCode(422);
    }

    public function test_unknown_version_returns_404(): void
    {
        $this->json('POST', '/api/v1/versions/999999/events', ['events' => [['type' => 'open']]]);

        $this->seeStatusCode(404);
    }
}
