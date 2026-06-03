<?php

namespace App\Http\Controllers;

use App\Models\Version;
use App\Services\IngestionService;
use Illuminate\Http\Request;

/**
 * Endpoint di ingestione massiva. Ogni metodo accetta un array di record sotto
 * una chiave dedicata (es. {"events":[...]}). La validazione resta volutamente
 * leggera (solo i campi indispensabili) per non rallentare batch da migliaia di
 * righe: i `payload` sono liberi per definizione.
 */
class IngestionController extends Controller
{
    /** @var IngestionService */
    private $service;

    public function __construct(IngestionService $service)
    {
        $this->service = $service;
    }

    public function players(Request $request, $versionId)
    {
        $this->ensureVersion($versionId);
        $this->validate($request, [
            'players' => 'required|array|min:1',
            'players.*.external_id' => 'required',
        ]);

        $inserted = $this->service->ingestPlayers((int) $versionId, $request->input('players'));

        return response()->json(['inserted' => $inserted], 201);
    }

    public function events(Request $request, $versionId)
    {
        $this->ensureVersion($versionId);
        $this->validate($request, [
            'events' => 'required|array|min:1',
            'events.*.type' => 'required|string|max:64',
        ]);

        $inserted = $this->service->ingestEvents((int) $versionId, $request->input('events'));

        return response()->json(['inserted' => $inserted], 201);
    }

    public function transactions(Request $request, $versionId)
    {
        $this->ensureVersion($versionId);
        $this->validate($request, ['transactions' => 'required|array|min:1']);

        $inserted = $this->service->ingestTransactions((int) $versionId, $request->input('transactions'));

        return response()->json(['inserted' => $inserted], 201);
    }

    public function answers(Request $request, $versionId)
    {
        $this->ensureVersion($versionId);
        $this->validate($request, [
            'answers' => 'required|array|min:1',
            'answers.*.question_id' => 'required',
        ]);

        $inserted = $this->service->ingestAnswers((int) $versionId, $request->input('answers'));

        return response()->json(['inserted' => $inserted], 201);
    }

    public function rewards(Request $request, $versionId)
    {
        $this->ensureVersion($versionId);
        $this->validate($request, [
            'rewards' => 'required|array|min:1',
            'rewards.*.type' => 'required|string|max:64',
        ]);

        $inserted = $this->service->ingestRewards((int) $versionId, $request->input('rewards'));

        return response()->json(['inserted' => $inserted], 201);
    }

    private function ensureVersion($versionId): void
    {
        Version::findOrFail($versionId);
    }
}
