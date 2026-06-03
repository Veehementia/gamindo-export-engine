<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessExportJob;
use App\Models\Export;
use App\Models\ExportTemplate;
use App\Models\Version;
use App\Services\Export\ExportDefinitionValidator;
use App\Services\Export\ExportEngine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Ramsey\Uuid\Uuid;

class ExportController extends Controller
{
    /** @var ExportDefinitionValidator */
    private $validator;

    public function __construct(ExportDefinitionValidator $validator)
    {
        $this->validator = $validator;
    }

    /**
     * POST /versions/{versionId}/exports
     * Crea la richiesta di export (stato pending) e accoda il job. Risponde 202.
     */
    public function store(Request $request, $versionId)
    {
        Version::findOrFail($versionId);

        $definition = $this->resolveDefinition($request, (int) $versionId);
        $definition = $this->validator->validate($definition);

        $export = Export::create([
            'uuid' => Uuid::uuid4()->toString(),
            'version_id' => (int) $versionId,
            'format' => $definition['format'],
            'status' => Export::STATUS_PENDING,
            'definition' => $definition,
        ]);

        // Fuori dal ciclo HTTP: la generazione avviene nel worker di coda.
        // $this->dispatch() è fornito dai trait del controller Lumen.
        $this->dispatch(new ProcessExportJob($export->id));

        return response()->json($this->resource($export), 202);
    }

    /**
     * POST /versions/{versionId}/exports/preview
     * (Bonus) Anteprima sincrona, max N righe per foglio, senza generare file.
     */
    public function preview(Request $request, $versionId, ExportEngine $engine)
    {
        Version::findOrFail($versionId);

        $definition = $this->resolveDefinition($request, (int) $versionId);
        $definition = $this->validator->validate($definition);

        $limit = (int) config('export.preview_rows', 100);
        $sheets = $engine->preview((int) $versionId, $definition, $limit);

        return response()->json([
            'limit_per_sheet' => $limit,
            'sheets' => $sheets,
        ]);
    }

    /** GET /exports/{export} */
    public function show($uuid)
    {
        $export = Export::where('uuid', $uuid)->firstOrFail();

        return response()->json($this->resource($export));
    }

    /** GET /exports/{export}/download */
    public function download($uuid)
    {
        $export = Export::where('uuid', $uuid)->firstOrFail();

        if (!$export->isDownloadable()) {
            return response()->json([
                'error' => 'File non disponibile.',
                'status' => $export->status,
            ], 409);
        }

        return \Illuminate\Support\Facades\Storage::disk(config('export.disk'))
            ->download($export->file_path, $export->file_name);
    }

    /**
     * POST /exports/{export}/cancel
     * (Bonus) Cancellazione: se è ancora in coda la chiude subito, se è in corso
     * imposta il flag che il job controlla a ogni batch.
     */
    public function cancel($uuid)
    {
        $export = Export::where('uuid', $uuid)->firstOrFail();

        if ($export->isTerminal()) {
            return response()->json([
                'error' => 'Export già concluso.',
                'status' => $export->status,
            ], 409);
        }

        if ($export->status === Export::STATUS_PENDING) {
            $export->forceFill([
                'status' => Export::STATUS_CANCELLED,
                'cancel_requested' => true,
                'completed_at' => Carbon::now(),
            ])->save();
        } else {
            $export->forceFill(['cancel_requested' => true])->save();
        }

        return response()->json($this->resource($export->fresh()));
    }

    /**
     * POST /exports/{export}/retry
     * (Bonus) Ri-accoda un export fallito o cancellato.
     */
    public function retry($uuid)
    {
        $export = Export::where('uuid', $uuid)->firstOrFail();

        if (!in_array($export->status, [Export::STATUS_FAILED, Export::STATUS_CANCELLED], true)) {
            return response()->json([
                'error' => 'Solo gli export falliti o cancellati possono essere ri-eseguiti.',
                'status' => $export->status,
            ], 409);
        }

        $export->forceFill([
            'status' => Export::STATUS_PENDING,
            'cancel_requested' => false,
            'progress' => 0,
            'rows_written' => 0,
            'error' => null,
            'file_path' => null,
            'file_name' => null,
            'file_size' => null,
            'started_at' => null,
            'completed_at' => null,
        ])->save();

        $this->dispatch(new ProcessExportJob($export->id));

        return response()->json($this->resource($export), 202);
    }

    // --------------------------------------------------------------- HELPERS

    /**
     * Risolve la definizione di export: da template (se `template_id`) oppure
     * dal corpo della richiesta. Eventuali campi nel body sovrascrivono il template.
     */
    private function resolveDefinition(Request $request, int $versionId): array
    {
        $base = [];

        if ($request->filled('template_id')) {
            $template = ExportTemplate::where('version_id', $versionId)
                ->where('id', $request->input('template_id'))
                ->firstOrFail();
            $base = $template->definition;
        }

        $overrides = array_filter(
            $request->only('format', 'date_from', 'date_to', 'sheets', 'report', 'filters'),
            function ($v) {
                return $v !== null;
            }
        );

        return array_merge(['format' => 'xlsx'], $base, $overrides);
    }

    private function resource(Export $export): array
    {
        $data = [
            'id' => $export->uuid,
            'version_id' => $export->version_id,
            'status' => $export->status,
            'format' => $export->format,
            'progress' => $export->progress,
            'rows_estimated' => $export->rows_estimated,
            'rows_written' => $export->rows_written,
            'attempts' => $export->attempts,
            'error' => $export->error,
            'file_name' => $export->file_name,
            'file_size' => $export->file_size,
            'created_at' => optional($export->created_at)->toIso8601String(),
            'started_at' => optional($export->started_at)->toIso8601String(),
            'completed_at' => optional($export->completed_at)->toIso8601String(),
            'definition' => $export->definition,
        ];

        if ($export->isDownloadable()) {
            $data['download_url'] = rtrim(config('app.url'), '/')
                . '/api/v1/exports/' . $export->uuid . '/download';
        }

        return $data;
    }
}
