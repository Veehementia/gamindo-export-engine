<?php

namespace Tests\Feature;

use App\Jobs\ProcessExportJob;
use App\Models\Export;
use App\Models\ExportTemplate;
use App\Models\Version;
use App\Services\Export\ExportEngine;
use App\Services\IngestionService;
use Laravel\Lumen\Testing\DatabaseMigrations;
use RuntimeException;
use Tests\TestCase;

/**
 * Test dei bonus: template salvabili, filtri su campi JSON, retry automatico,
 * cancellazione dell'export.
 */
class BonusFeaturesTest extends TestCase
{
    use DatabaseMigrations;

    private function seedVersion(): Version
    {
        $version = Version::create(['name' => 'Bonus V']);
        /** @var IngestionService $ingest */
        $ingest = app(IngestionService::class);

        // 3 player: 2 italiani, 1 inglese.
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

    // =================================================== TEMPLATE SALVABILI

    public function test_export_template_can_be_created_and_listed(): void
    {
        $version = $this->seedVersion();

        $this->json('POST', "/api/v1/versions/{$version->id}/export-templates", [
            'name' => 'report-it',
            'description' => 'Solo player italiani',
            'definition' => [
                'sheets' => [[
                    'name' => 'players',
                    'columns' => ['email', 'payload.language'],
                    'filters' => ['payload.language' => 'it'],
                ]],
            ],
        ]);
        $this->seeStatusCode(201);
        $this->seeInDatabase('export_templates', ['version_id' => $version->id, 'name' => 'report-it']);

        $this->json('GET', "/api/v1/versions/{$version->id}/export-templates");
        $this->seeStatusCode(200);
        $list = json_decode($this->response->getContent(), true);
        $this->assertCount(1, $list);
        $this->assertSame('report-it', $list[0]['name']);
    }

    public function test_invalid_template_definition_is_rejected(): void
    {
        $version = $this->seedVersion();

        $this->json('POST', "/api/v1/versions/{$version->id}/export-templates", [
            'name' => 'rotto',
            'definition' => [
                'sheets' => [[
                    'name' => 'players',
                    'columns' => ['email', 'password'], // colonna fuori whitelist
                ]],
            ],
        ]);

        $this->seeStatusCode(422);
    }

    public function test_export_can_be_generated_from_a_saved_template(): void
    {
        $version = $this->seedVersion();

        $this->json('POST', "/api/v1/versions/{$version->id}/export-templates", [
            'name' => 'solo-it',
            'definition' => [
                'sheets' => [[
                    'name' => 'players',
                    'columns' => ['player_id', 'email', 'payload.language'],
                    'filters' => ['payload.language' => 'it'],
                ]],
            ],
        ]);
        $template = ExportTemplate::where('version_id', $version->id)->firstOrFail();

        // Richiedo l'export usando SOLO il template_id (sync => gira subito).
        $this->json('POST', "/api/v1/versions/{$version->id}/exports", ['template_id' => $template->id]);
        $this->seeStatusCode(202);
        $uuid = json_decode($this->response->getContent(), true)['id'];

        $this->json('GET', "/api/v1/exports/{$uuid}");
        $data = json_decode($this->response->getContent(), true);
        $this->assertSame(Export::STATUS_COMPLETED, $data['status']);
        $this->assertSame(2, $data['rows_written']); // il template filtra language=it
    }

    // =================================================== FILTRI SU CAMPI JSON

    public function test_json_field_equality_filter(): void
    {
        $version = $this->seedVersion();

        $rows = $this->previewRows($version->id, [
            'name' => 'players',
            'columns' => ['email', 'payload.language'],
            'filters' => ['payload.language' => 'it'],
        ]);

        $this->assertCount(2, $rows);
    }

    public function test_json_field_in_list_filter(): void
    {
        $version = $this->seedVersion();

        // IN con un solo valore presente => solo il player inglese.
        $rows = $this->previewRows($version->id, [
            'name' => 'players',
            'columns' => ['email', 'payload.language'],
            'filters' => ['payload.language' => ['en']],
        ]);
        $this->assertCount(1, $rows);

        // IN con due valori => tutti e tre.
        $rows = $this->previewRows($version->id, [
            'name' => 'players',
            'columns' => ['email', 'payload.language'],
            'filters' => ['payload.language' => ['it', 'en']],
        ]);
        $this->assertCount(3, $rows);
    }

    public function test_json_filter_on_events_source(): void
    {
        $version = $this->seedVersion();

        $rows = $this->previewRows($version->id, [
            'name' => 'events',
            'columns' => ['player_id', 'type', 'payload.language'],
            'filters' => ['payload.language' => 'en'],
        ]);

        $this->assertCount(1, $rows); // solo l'evento del player inglese
    }

    /** Esegue una preview di un singolo foglio e ritorna le righe del foglio. */
    private function previewRows(int $versionId, array $sheet): array
    {
        $this->json('POST', "/api/v1/versions/{$versionId}/exports/preview", ['sheets' => [$sheet]]);
        $this->seeStatusCode(200);
        $data = json_decode($this->response->getContent(), true);
        return $data['sheets'][0]['rows'];
    }

    // =================================================== RETRY AUTOMATICO

    public function test_export_job_has_automatic_retry_configured(): void
    {
        $job = new ProcessExportJob(123);

        $this->assertSame((int) config('export.job_tries', 3), $job->tries);
        $this->assertIsArray($job->backoff());
        $this->assertNotEmpty($job->backoff()); // attese crescenti tra i tentativi
    }

    public function test_failed_job_marks_export_as_failed(): void
    {
        $version = $this->seedVersion();
        $export = Export::create([
            'uuid' => 'to-fail-1',
            'version_id' => $version->id,
            'format' => 'xlsx',
            'status' => Export::STATUS_PROCESSING,
            'definition' => ['format' => 'xlsx', 'sheets' => [['name' => 'players', 'columns' => ['email']]]],
        ]);

        // Simula l'invocazione finale del meccanismo di retry (tentativi esauriti).
        (new ProcessExportJob($export->id))->failed(new RuntimeException('boom esploso'));

        $fresh = Export::find($export->id);
        $this->assertSame(Export::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('boom', $fresh->error);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_retry_is_rejected_when_export_is_not_failed(): void
    {
        $version = $this->seedVersion();
        $export = Export::create([
            'uuid' => 'completed-1',
            'version_id' => $version->id,
            'format' => 'xlsx',
            'status' => Export::STATUS_COMPLETED,
            'definition' => ['format' => 'xlsx', 'sheets' => [['name' => 'players', 'columns' => ['email']]]],
        ]);

        $this->json('POST', "/api/v1/exports/{$export->uuid}/retry");
        $this->seeStatusCode(409); // solo failed/cancelled possono essere ri-eseguiti
    }

    // =================================================== CANCELLAZIONE

    public function test_pending_export_is_cancelled_immediately(): void
    {
        $version = $this->seedVersion();
        $export = Export::create([
            'uuid' => 'pending-1',
            'version_id' => $version->id,
            'format' => 'xlsx',
            'status' => Export::STATUS_PENDING,
            'definition' => ['format' => 'xlsx', 'sheets' => [['name' => 'players', 'columns' => ['email']]]],
        ]);

        $this->json('POST', "/api/v1/exports/{$export->uuid}/cancel");
        $this->seeStatusCode(200);

        $fresh = Export::find($export->id);
        $this->assertSame(Export::STATUS_CANCELLED, $fresh->status);
        $this->assertTrue((bool) $fresh->cancel_requested);
    }

    public function test_cancel_is_rejected_when_export_is_terminal(): void
    {
        $version = $this->seedVersion();
        $export = Export::create([
            'uuid' => 'done-1',
            'version_id' => $version->id,
            'format' => 'xlsx',
            'status' => Export::STATUS_COMPLETED,
            'definition' => ['format' => 'xlsx', 'sheets' => [['name' => 'players', 'columns' => ['email']]]],
        ]);

        $this->json('POST', "/api/v1/exports/{$export->uuid}/cancel");
        $this->seeStatusCode(409);
    }

    public function test_engine_stops_when_cancellation_is_requested_mid_run(): void
    {
        $version = $this->seedVersion();
        $export = Export::create([
            'uuid' => 'cancel-mid-1',
            'version_id' => $version->id,
            'format' => 'xlsx',
            'status' => Export::STATUS_PENDING,
            'definition' => ['format' => 'xlsx', 'date_from' => null, 'date_to' => null,
                'sheets' => [['name' => 'players', 'columns' => ['player_id', 'email']]]],
            'cancel_requested' => true, // cancellazione già richiesta
        ]);

        // progress_batch=1 => il motore controlla la cancellazione dopo la prima riga.
        config(['export.progress_batch' => 1]);
        (new ExportEngine())->process($export);

        $fresh = Export::find($export->id);
        $this->assertSame(Export::STATUS_CANCELLED, $fresh->status);
        $this->assertNull($fresh->file_path); // nessun file prodotto
    }
}
