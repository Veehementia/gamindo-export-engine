<?php

namespace Tests\Feature;

use App\Models\Export;
use App\Models\Version;
use App\Services\IngestionService;
use Laravel\Lumen\Testing\DatabaseMigrations;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use DatabaseMigrations;

    private function seedVersion(): Version
    {
        $version = Version::create(['name' => 'Export V']);
        /** @var IngestionService $ingest */
        $ingest = app(IngestionService::class);

        $ingest->ingestPlayers($version->id, [
            ['external_id' => 'p1', 'email' => 'a@x.it', 'registered_at' => '2026-01-05', 'total_score' => 100, 'payload' => ['language' => 'it']],
            ['external_id' => 'p2', 'email' => 'b@x.en', 'registered_at' => '2026-01-06', 'total_score' => 200, 'payload' => ['language' => 'en']],
            ['external_id' => 'p3', 'email' => 'c@x.it', 'registered_at' => '2026-01-07', 'total_score' => 300, 'payload' => ['language' => 'it']],
        ]);

        $ingest->ingestEvents($version->id, [
            ['player_id' => 1, 'type' => 'open', 'occurred_at' => '2026-01-05 10:00:00', 'payload' => ['language' => 'it']],
            ['player_id' => 1, 'type' => 'complete', 'occurred_at' => '2026-01-06 10:00:00', 'payload' => ['language' => 'it']],
            ['player_id' => 2, 'type' => 'open', 'occurred_at' => '2026-01-07 10:00:00', 'payload' => ['language' => 'en']],
        ]);

        return $version;
    }

    public function test_export_runs_and_is_downloadable(): void
    {
        $version = $this->seedVersion();

        // QUEUE_CONNECTION=sync nei test: il job gira subito durante la richiesta.
        $this->json('POST', "/api/v1/versions/{$version->id}/exports", [
            'sheets' => [[
                'name' => 'players',
                'columns' => ['player_id', 'email', 'registered_at', 'total_score', 'payload.language'],
                'filters' => ['payload.language' => 'it'],
                'sort' => ['registered_at:desc'],
            ]],
        ]);
        $this->seeStatusCode(202);

        $uuid = json_decode($this->response->getContent(), true)['id'];

        $this->json('GET', "/api/v1/exports/{$uuid}");
        $data = json_decode($this->response->getContent(), true);

        $this->assertSame(Export::STATUS_COMPLETED, $data['status']);
        $this->assertSame(2, $data['rows_written']); // solo i player con language=it
        $this->assertArrayHasKey('download_url', $data);

        $this->get("/api/v1/exports/{$uuid}/download");
        $this->seeStatusCode(200);
    }

    public function test_aggregation_sheet_groups_and_counts(): void
    {
        $version = $this->seedVersion();

        $this->json('POST', "/api/v1/versions/{$version->id}/exports", [
            'sheets' => [[
                'name' => 'events_summary',
                'group_by' => ['type', 'payload.language'],
                'metrics' => ['count', 'unique_players'],
            ]],
        ]);
        $this->seeStatusCode(202);
        $uuid = json_decode($this->response->getContent(), true)['id'];

        $this->json('GET', "/api/v1/exports/{$uuid}");
        $data = json_decode($this->response->getContent(), true);

        $this->assertSame(Export::STATUS_COMPLETED, $data['status']);
        // gruppi attesi: (open,it),(complete,it),(open,en) = 3
        $this->assertSame(3, $data['rows_written']);
    }

    public function test_preview_returns_rows_without_file(): void
    {
        $version = $this->seedVersion();

        $this->json('POST', "/api/v1/versions/{$version->id}/exports/preview", [
            'sheets' => [[
                'name' => 'players',
                'columns' => ['email', 'payload.language'],
                'filters' => ['payload.language' => 'it'],
            ]],
        ]);

        $this->seeStatusCode(200);
        $data = json_decode($this->response->getContent(), true);
        $this->assertCount(2, $data['sheets'][0]['rows']);
    }

    public function test_invalid_column_is_rejected_with_422(): void
    {
        $version = $this->seedVersion();

        $this->json('POST', "/api/v1/versions/{$version->id}/exports", [
            'sheets' => [[
                'name' => 'players',
                'columns' => ['email', 'password'], // "password" non è in whitelist
            ]],
        ]);

        $this->seeStatusCode(422);
    }

    public function test_retry_requeues_a_failed_export(): void
    {
        $version = $this->seedVersion();
        $export = Export::create([
            'uuid' => 'failed-uuid-1',
            'version_id' => $version->id,
            'format' => 'xlsx',
            'status' => Export::STATUS_FAILED,
            'definition' => ['format' => 'xlsx', 'sheets' => [['name' => 'players', 'columns' => ['email']]]],
            'error' => 'boom',
        ]);

        $this->json('POST', "/api/v1/exports/{$export->uuid}/retry");
        $this->seeStatusCode(202);

        // Dopo il retry (sync) l'export è stato rigenerato con successo.
        $this->json('GET', "/api/v1/exports/{$export->uuid}");
        $data = json_decode($this->response->getContent(), true);
        $this->assertSame(Export::STATUS_COMPLETED, $data['status']);
    }
}
