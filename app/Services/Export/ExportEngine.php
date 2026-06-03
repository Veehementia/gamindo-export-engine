<?php

namespace App\Services\Export;

use App\Models\Export;
use Carbon\Carbon;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Orchestratore della generazione dell'export.
 *
 * Invariante di memoria: NON materializziamo mai l'intero result set. Le righe
 * arrivano in streaming dal DB (cursor su connessione unbuffered) e vengono
 * scritte subito su disco da OpenSpout. Così un export da 500k righe usa la
 * stessa RAM di uno da 100 righe.
 */
class ExportEngine
{
    /** @var string */
    private $disk;
    /** @var string */
    private $directory;
    /** @var string */
    private $readConnection;
    /** @var int */
    private $progressBatch;
    /** @var int */
    private $maxRows;

    public function __construct()
    {
        $this->disk = config('export.disk');
        $this->directory = config('export.directory');
        $this->readConnection = config('export.read_connection') ?: DB::getDefaultConnection();
        $this->progressBatch = max(1, (int) config('export.progress_batch'));
        $this->maxRows = (int) config('export.max_rows');
    }

    /**
     * Genera il file per l'export indicato. Aggiorna stato/progress sul modello.
     * Solleva un'eccezione in caso di errore (gestita dal Job per il retry).
     */
    public function process(Export $export): void
    {
        $export->forceFill([
            'status' => Export::STATUS_PROCESSING,
            'started_at' => $export->started_at ?: Carbon::now(),
            'attempts' => $export->attempts + 1,
            'progress' => 0,
            'rows_written' => 0,
            'error' => null,
        ])->save();

        $definition = $export->definition;
        $sheets = $this->buildSheets($export->version_id, $definition);

        $totalRows = array_sum(array_column($sheets, 'estimated'));
        if ($totalRows > $this->maxRows) {
            throw new RuntimeException(
                "L'export supera il limite di {$this->maxRows} righe (stimate: {$totalRows})."
            );
        }
        $export->forceFill(['rows_estimated' => $totalRows])->save();

        // Scriviamo su un file temporaneo locale, poi lo depositiamo sul disco
        // configurato (local/s3) in streaming.
        $tmpPath = tempnam(sys_get_temp_dir(), 'export_') . '.xlsx';
        $writer = new XlsxWriter();
        $writer->open($tmpPath);

        $written = 0;
        $cancelled = false;

        try {
            foreach ($sheets as $sheet) {
                $writer->startSheet($sheet['name'], $sheet['headers']);

                foreach ($this->sheetRows($sheet) as $values) {
                    $writer->addRow($values);
                    $written++;

                    // Ogni batch: aggiorniamo il progress e controlliamo la
                    // cancellazione (una sola query DB ogni N righe, non per riga).
                    if ($written % $this->progressBatch === 0) {
                        $this->updateProgress($export, $written, $totalRows);
                        if ($this->isCancelRequested($export->id)) {
                            $cancelled = true;
                            break 2;
                        }
                    }
                }
            }
        } finally {
            $writer->close();
        }

        if ($cancelled) {
            @unlink($tmpPath);
            $export->forceFill([
                'status' => Export::STATUS_CANCELLED,
                'completed_at' => Carbon::now(),
            ])->save();
            return;
        }

        // Deposito definitivo del file.
        $fileName = $this->fileName($export);
        $storedPath = Storage::disk($this->disk)->putFileAs(
            $this->directory,
            new File($tmpPath),
            $fileName
        );
        $size = Storage::disk($this->disk)->size($storedPath);
        @unlink($tmpPath);

        $export->forceFill([
            'status' => Export::STATUS_COMPLETED,
            'progress' => 100,
            'rows_written' => $written,
            'file_path' => $storedPath,
            'file_name' => $fileName,
            'file_size' => $size,
            'completed_at' => Carbon::now(),
        ])->save();
    }

    /**
     * Anteprima sincrona (max N righe per foglio), senza generare file.
     * Usata dall'endpoint di preview.
     *
     * @return array<int,array{name:string,headers:array,rows:array}>
     */
    public function preview(int $versionId, array $definition, int $limit): array
    {
        $sheets = $this->isReport($definition)
            ? (new VersionReportBuilder($this->readConnection))->build($versionId, $definition)
            : $this->genericSheets($versionId, $definition);

        $result = [];
        foreach ($sheets as $sheet) {
            if (isset($sheet['rows'])) {
                $rows = array_slice($sheet['rows'], 0, $limit);
            } else {
                $rows = [];
                foreach ($sheet['query']->limit($limit)->get() as $row) {
                    $rows[] = $this->mapRow($sheet['headers'], $row);
                }
            }

            $result[] = [
                'name' => $sheet['name'],
                'headers' => $sheet['headers'],
                'rows' => $rows,
            ];
        }

        return $result;
    }

    // ------------------------------------------------------------- INTERNALS

    /**
     * Una richiesta è in modalità "report" quando chiede esplicitamente
     * `report=full` oppure quando non specifica fogli custom: in quel caso
     * generiamo il report completo e curato (profilo full).
     */
    private function isReport(array $definition): bool
    {
        return ($definition['report'] ?? null) === 'full' || empty($definition['sheets']);
    }

    /**
     * Costruisce i fogli (report o custom) e ne calcola il conteggio righe stimato.
     */
    private function buildSheets(int $versionId, array $definition): array
    {
        $sheets = $this->isReport($definition)
            ? (new VersionReportBuilder($this->readConnection))->build($versionId, $definition)
            : $this->genericSheets($versionId, $definition);

        foreach ($sheets as &$sheet) {
            $sheet['estimated'] = isset($sheet['query'])
                ? (clone $sheet['query'])->getCountForPagination()
                : count($sheet['rows']);
        }
        unset($sheet);

        return $sheets;
    }

    /**
     * Fogli "custom" come da definizione del client (un foglio = un dataset).
     */
    private function genericSheets(int $versionId, array $definition): array
    {
        $builder = new SheetQueryBuilder($this->readConnection);
        $sheets = [];

        foreach ($definition['sheets'] as $sheet) {
            $source = DatasetRegistry::resolveSource($sheet);
            $dataset = DatasetRegistry::get($source);
            $built = $builder->build(
                $dataset,
                $versionId,
                $sheet,
                $definition['date_from'] ?? null,
                $definition['date_to'] ?? null
            );

            $sheets[] = [
                'name' => $sheet['name'],
                'headers' => $built['headers'],
                'query' => $built['query'],
            ];
        }

        return $sheets;
    }

    /**
     * Itera le righe di un foglio in modo uniforme: streaming (cursor) per i
     * fogli con `query`, materializzato per quelli con `rows`.
     *
     * @return \Generator
     */
    private function sheetRows(array $sheet)
    {
        if (isset($sheet['rows'])) {
            foreach ($sheet['rows'] as $row) {
                yield $row;
            }
            return;
        }

        $headers = $sheet['headers'];
        foreach ($sheet['query']->cursor() as $row) {
            yield $this->mapRow($headers, $row);
        }
    }

    /** Mappa una riga del DB sull'ordine delle intestazioni. */
    private function mapRow(array $headers, $row): array
    {
        $arr = (array) $row;
        $values = [];
        foreach ($headers as $header) {
            $values[] = $arr[$header] ?? null;
        }
        return $values;
    }

    private function updateProgress(Export $export, int $written, int $total): void
    {
        $percent = $total > 0 ? (int) floor(100 * $written / $total) : 0;
        $percent = max(0, min(99, $percent)); // 100 solo a completamento

        // Update mirato senza ricaricare il modello (la connessione di default
        // è diversa da quella di lettura unbuffered).
        DB::table('exports')->where('id', $export->id)->update([
            'rows_written' => $written,
            'progress' => $percent,
            'updated_at' => Carbon::now(),
        ]);
    }

    private function isCancelRequested(int $exportId): bool
    {
        return (bool) DB::table('exports')->where('id', $exportId)->value('cancel_requested');
    }

    private function fileName(Export $export): string
    {
        return 'export_' . $export->uuid . '.xlsx';
    }
}
