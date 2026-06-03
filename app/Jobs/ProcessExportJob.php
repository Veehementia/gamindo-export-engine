<?php

namespace App\Jobs;

use App\Models\Export;
use App\Services\Export\ExportEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job che genera l'export fuori dal ciclo richiesta HTTP.
 *
 * Affidabilità:
 *  - `$tries`  : tentativi automatici in caso di errore (retry).
 *  - `backoff(): ritardo crescente tra i tentativi (evita di martellare un DB
 *    momentaneamente sovraccarico).
 *  - `failed()`: dopo l'ultimo tentativo fallito, marca l'export come "failed"
 *    e ne registra l'errore, così l'API può comunicarlo al client.
 *
 * Passiamo solo l'ID (non il modello) per ricaricare sempre lo stato fresco:
 * tra l'accodamento e l'esecuzione qualcuno potrebbe aver chiesto la cancellazione.
 */
class ProcessExportJob extends Job
{
    /** @var int */
    public $tries;

    /** @var int secondi */
    public $timeout;

    /** @var int */
    private $exportId;

    public function __construct(int $exportId)
    {
        $this->exportId = $exportId;
        $this->tries = (int) config('export.job_tries', 3);
        $this->timeout = (int) config('export.job_timeout', 3600);
        $this->onQueue('exports');
    }

    /** Ritardo (secondi) tra un tentativo e il successivo. */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(ExportEngine $engine): void
    {
        /** @var Export|null $export */
        $export = Export::find($this->exportId);
        if (!$export) {
            return; // export rimosso nel frattempo
        }

        // Cancellazione richiesta prima ancora di iniziare.
        if ($export->cancel_requested) {
            $export->forceFill([
                'status' => Export::STATUS_CANCELLED,
                'completed_at' => Carbon::now(),
            ])->save();
            return;
        }

        $engine->process($export);
    }

    /** Invocato quando i tentativi sono esauriti. */
    public function failed(Throwable $e): void
    {
        Log::error('Export fallito', ['export_id' => $this->exportId, 'error' => $e->getMessage()]);

        $export = Export::find($this->exportId);
        if ($export && !$export->isTerminal()) {
            $export->forceFill([
                'status' => Export::STATUS_FAILED,
                'error' => $e->getMessage(),
                'completed_at' => Carbon::now(),
            ])->save();
        }
    }
}
