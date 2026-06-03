<?php

namespace Tests\Feature;

use App\Models\Export;
use App\Models\Version;
use App\Services\IngestionService;
use Laravel\Lumen\Testing\DatabaseMigrations;
use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use DatabaseMigrations;

    private function seedVersion(): Version
    {
        $version = Version::create(['name' => 'Report V']);
        /** @var IngestionService $ingest */
        $ingest = app(IngestionService::class);

        $ingest->ingestPlayers($version->id, [
            ['external_id' => 'P1', 'email' => 'a@x.it', 'registered_at' => '2026-01-01', 'total_score' => 900, 'payload' => ['language' => 'it', 'utm_source' => 'linkedin', 'company' => 'Hooli', 'marketing_optin' => 'yes']],
            ['external_id' => 'P2', 'email' => 'b@x.en', 'registered_at' => '2026-01-02', 'total_score' => 400, 'payload' => ['language' => 'en', 'utm_source' => 'google', 'company' => 'Globex', 'marketing_optin' => 'no']],
        ]);
        $ingest->ingestEvents($version->id, [
            ['player_id' => 1, 'type' => 'open', 'occurred_at' => '2026-01-03 10:00:00', 'payload' => ['language' => 'it', 'utm_source' => 'linkedin', 'score' => 100]],
            ['player_id' => 1, 'type' => 'complete', 'occurred_at' => '2026-01-04 10:00:00', 'payload' => ['language' => 'it', 'utm_source' => 'linkedin', 'score' => 800]],
            ['player_id' => 2, 'type' => 'open', 'occurred_at' => '2026-01-05 10:00:00', 'payload' => ['language' => 'en', 'utm_source' => 'google', 'score' => 50]],
        ]);
        $ingest->ingestTransactions($version->id, [
            ['player_id' => 1, 'amount' => 5000, 'status' => 'completed', 'occurred_at' => '2026-01-06 10:00:00', 'payload' => ['type' => 'purchase']],
        ]);
        $ingest->ingestAnswers($version->id, [
            ['player_id' => 1, 'question_id' => 'Q1', 'answer' => 'A', 'occurred_at' => '2026-01-07 10:00:00', 'payload' => ['question' => 'Domanda 1?']],
            ['player_id' => 2, 'question_id' => 'Q1', 'answer' => 'B', 'occurred_at' => '2026-01-07 10:00:00', 'payload' => ['question' => 'Domanda 1?']],
        ]);

        return $version;
    }

    public function test_full_report_generates_the_eight_sheets(): void
    {
        $version = $this->seedVersion();

        $this->json('POST', "/api/v1/versions/{$version->id}/exports", [
            'report' => 'full',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
        ]);
        $this->seeStatusCode(202);
        $uuid = json_decode($this->response->getContent(), true)['id'];

        $this->json('GET', "/api/v1/exports/{$uuid}");
        $data = json_decode($this->response->getContent(), true);
        $this->assertSame(Export::STATUS_COMPLETED, $data['status']);
        $this->assertGreaterThan(0, $data['rows_written']);

        $export = Export::where('uuid', $uuid)->firstOrFail();
        $path = storage_path('app/' . $export->file_path);
        $this->assertFileExists($path);

        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->open($path);
        $names = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $names[] = $sheet->getName();
        }
        $reader->close();
        @unlink($path);

        $this->assertSame([
            'README', 'KPIs', 'Configurazione_Richiesta', 'Players',
            'Events_Summary', 'Answers', 'Transactions', 'Data_Quality',
        ], $names);
    }

    public function test_export_without_sheets_defaults_to_full_report(): void
    {
        $version = $this->seedVersion();

        $this->json('POST', "/api/v1/versions/{$version->id}/exports", []);
        $this->seeStatusCode(202);

        $data = json_decode($this->response->getContent(), true);
        $this->assertSame('full', $data['definition']['report']);
        $this->assertSame(Export::STATUS_PENDING, $data['status']);

        // Con coda sync il job è già girato: lo stato finale è completed.
        $this->json('GET', "/api/v1/exports/{$data['id']}");
        $final = json_decode($this->response->getContent(), true);
        $this->assertSame(Export::STATUS_COMPLETED, $final['status']);

        $export = Export::where('uuid', $data['id'])->firstOrFail();
        @unlink(storage_path('app/' . $export->file_path));
    }
}
