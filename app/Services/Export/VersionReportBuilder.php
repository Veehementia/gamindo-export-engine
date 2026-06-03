<?php

namespace App\Services\Export;

use Illuminate\Support\Facades\DB;

/**
 * Costruisce un REPORT "completo" e curato di una version, fedele all'esempio
 * di consegna: più fogli (README, KPI, configurazione, dati grezzi arricchiti,
 * aggregazioni, qualità dati).
 *
 * A differenza del SheetQueryBuilder (un foglio = una tabella, colonne dirette),
 * qui i fogli sono "ricchi": colonne derivate da JOIN/aggregazioni per player,
 * KPI calcolati, controlli di data quality. È la modalità che il motore usa
 * quando la richiesta non specifica fogli custom (profilo "full").
 *
 * Ogni foglio è descritto come:
 *   ['name'=>..., 'headers'=>[...], 'query'=>Builder]   -> grande, in streaming
 *   ['name'=>..., 'headers'=>[...], 'rows'=>[[...],...]] -> piccolo, materializzato
 */
class VersionReportBuilder
{
    /** @var string connessione per le query in streaming (unbuffered su MySQL) */
    private $readConnection;

    /** @var string connessione per le query "piccole" (buffered) */
    private $connection;

    /** @var string driver DB ("mysql" | "sqlite") */
    private $driver;

    public function __construct(string $readConnection)
    {
        $this->readConnection = $readConnection;
        $this->connection = DB::getDefaultConnection();
        $this->driver = DB::connection($this->connection)->getDriverName();
    }

    /**
     * @return array<int,array> elenco ordinato di fogli del report
     */
    public function build(int $versionId, array $definition): array
    {
        $from = $definition['date_from'] ?? null;
        $to = $definition['date_to'] ?? null;
        $lang = $definition['filters']['payload.language'] ?? null;
        if ($lang === '*') {
            $lang = null;
        }

        $version = DB::connection($this->connection)->table('versions')->find($versionId);
        $playersCount = $this->playersBaseQuery($versionId, $from, $to, $lang)->count();

        $dataQuality = $this->dataQualityRows($versionId, $from, $to);
        $kpis = $this->kpiRows($versionId, $from, $to, $lang, $dataQuality);

        return [
            $this->readmeSheet($version, $versionId, $from, $to, $lang, $playersCount),
            $this->kpiSheet($kpis),
            $this->configSheet($definition, $from, $to, $lang),
            $this->playersSheet($versionId, $from, $to, $lang),
            $this->eventsSummarySheet($versionId, $from, $to, $lang),
            $this->answersSheet($versionId, $from, $to),
            $this->transactionsSheet($versionId, $from, $to),
            $this->dataQualitySheet($dataQuality),
        ];
    }

    // ===================================================== FOGLI "GRANDI"

    /** Foglio Players: anagrafica arricchita con metriche derivate (join). */
    private function playersSheet(int $versionId, $from, $to, $lang): array
    {
        $conn = $this->readConnection;
        $langExpr = $this->json('players.payload', 'language');
        $utmExpr = $this->json('players.payload', 'utm_source');
        $companyExpr = $this->json('players.payload', 'company');
        $optinExpr = $this->json('players.payload', 'marketing_optin');

        // Sub-aggregazione eventi per player (conteggi e "stato").
        $events = DB::connection($conn)->table('events')->where('version_id', $versionId);
        $this->applyDate($events, 'occurred_at', $from, $to);
        $events->groupBy('player_id')->selectRaw(
            "player_id, COUNT(*) as events_count, " .
            "SUM(CASE WHEN type = 'complete' THEN 1 ELSE 0 END) as completed_count, " .
            "SUM(CASE WHEN type = 'register' THEN 1 ELSE 0 END) as registered_count"
        );

        // Sub-aggregazione transazioni completate per player (ricavo).
        $tx = DB::connection($conn)->table('transactions')
            ->where('version_id', $versionId)->where('status', 'completed');
        $this->applyDate($tx, 'occurred_at', $from, $to);
        $tx->groupBy('player_id')->selectRaw('player_id, SUM(amount) as revenue_cents');

        // Sub-aggregazione premio rappresentativo per player.
        $rw = DB::connection($conn)->table('rewards')->where('version_id', $versionId)
            ->groupBy('player_id')->selectRaw('player_id, MAX(type) as reward');

        $query = DB::connection($conn)->table('players')
            ->leftJoinSub($events, 'ev', 'ev.player_id', '=', 'players.id')
            ->leftJoinSub($tx, 'tx', 'tx.player_id', '=', 'players.id')
            ->leftJoinSub($rw, 'rw', 'rw.player_id', '=', 'players.id')
            ->where('players.version_id', $versionId);

        $this->applyDate($query, 'players.registered_at', $from, $to);
        $this->applyLang($query, $langExpr, $lang);

        $query->selectRaw(implode(', ', [
            'players.external_id as player_id',
            'players.email as email',
            'players.registered_at as registered_at',
            "$langExpr as language",
            "$utmExpr as utm_source",
            "$companyExpr as company",
            'players.total_score as total_score',
            'COALESCE(ev.completed_count, 0) as levels_completed',
            'COALESCE(ev.events_count, 0) as events_count',
            "COALESCE(rw.reward, 'none') as reward",
            "CASE WHEN COALESCE(ev.completed_count,0) > 0 THEN 'completed' " .
            "WHEN COALESCE(ev.registered_count,0) > 0 THEN 'registered' " .
            "WHEN COALESCE(ev.events_count,0) > 0 THEN 'started' ELSE 'new' END as status",
            "$optinExpr as marketing_optin",
            'ROUND(COALESCE(tx.revenue_cents, 0) / 100.0, 2) as revenue',
        ]))->orderBy('players.total_score', 'desc')->orderBy('players.id');

        return [
            'name' => 'Players',
            'headers' => ['player_id', 'email', 'registered_at', 'language', 'utm_source',
                'company', 'total_score', 'levels_completed', 'events_count', 'reward',
                'status', 'marketing_optin', 'revenue'],
            'query' => $query,
        ];
    }

    /** Foglio Events_Summary: aggregazione per tipo/lingua/sorgente con metriche. */
    private function eventsSummarySheet(int $versionId, $from, $to, $lang): array
    {
        $conn = $this->readConnection;
        $langExpr = $this->json('events.payload', 'language');
        $utmExpr = $this->json('events.payload', 'utm_source');
        $scoreExpr = $this->json('events.payload', 'score');

        $query = DB::connection($conn)->table('events')->where('version_id', $versionId);
        $this->applyDate($query, 'occurred_at', $from, $to);
        $this->applyLang($query, $langExpr, $lang);

        $query->selectRaw(implode(', ', [
            'type as event_type',
            "$langExpr as language",
            "$utmExpr as utm_source",
            'COUNT(*) as events_count',
            'COUNT(DISTINCT player_id) as unique_players',
            'ROUND(COUNT(*) * 1.0 / COUNT(DISTINCT player_id), 2) as events_per_player',
            "ROUND(AVG($scoreExpr + 0.0), 2) as avg_score",
        ]))
            ->groupByRaw("type, $langExpr, $utmExpr")
            ->orderByRaw("type, $langExpr, $utmExpr");

        return [
            'name' => 'Events_Summary',
            'headers' => ['event_type', 'language', 'utm_source', 'events_count',
                'unique_players', 'events_per_player', 'avg_score'],
            'query' => $query,
        ];
    }

    /** Foglio Transactions: transazioni con email/lingua/sorgente dal player (join). */
    private function transactionsSheet(int $versionId, $from, $to): array
    {
        $conn = $this->readConnection;
        $code = $this->driver === 'sqlite'
            ? "('TX-' || transactions.id)"
            : "CONCAT('TX-', transactions.id)";
        $payloadCode = $this->json('transactions.payload', 'transaction_id');
        $payloadType = $this->json('transactions.payload', 'type');
        $pLang = $this->json('players.payload', 'language');
        $pUtm = $this->json('players.payload', 'utm_source');

        $query = DB::connection($conn)->table('transactions')
            ->leftJoin('players', 'players.id', '=', 'transactions.player_id')
            ->where('transactions.version_id', $versionId);
        $this->applyDate($query, 'transactions.occurred_at', $from, $to);

        $query->selectRaw(implode(', ', [
            "COALESCE($payloadCode, $code) as transaction_id",
            'players.external_id as player_id',
            'players.email as email',
            "COALESCE($payloadType, transactions.status) as type",
            'ROUND(transactions.amount / 100.0, 2) as amount',
            "$pLang as language",
            "$pUtm as utm_source",
            'transactions.occurred_at as occurred_at',
        ]))->orderBy('transactions.occurred_at');

        return [
            'name' => 'Transactions',
            'headers' => ['transaction_id', 'player_id', 'email', 'type', 'amount',
                'language', 'utm_source', 'occurred_at'],
            'query' => $query,
        ];
    }

    // ===================================================== FOGLI "PICCOLI"

    /** Foglio Answers: distribuzione risposte per domanda + percentuale. */
    private function answersSheet(int $versionId, $from, $to): array
    {
        $conn = $this->connection;
        $qExpr = $this->json('answers.payload', 'question');

        $query = DB::connection($conn)->table('answers')->where('version_id', $versionId);
        $this->applyDate($query, 'occurred_at', $from, $to);
        $records = $query->selectRaw("question_id, $qExpr as question, answer, COUNT(*) as answers_count")
            ->groupByRaw("question_id, $qExpr, answer")
            ->orderBy('question_id')
            ->orderBy('answer')
            ->get();

        // Totale risposte per domanda (per la percentuale).
        $totals = [];
        foreach ($records as $r) {
            $totals[$r->question_id] = ($totals[$r->question_id] ?? 0) + (int) $r->answers_count;
        }

        $rows = [];
        foreach ($records as $r) {
            $total = $totals[$r->question_id] ?: 1;
            $rows[] = [
                $r->question_id,
                $r->question ?? '',
                $r->answer,
                (int) $r->answers_count,
                round($r->answers_count / $total, 4),
            ];
        }

        return [
            'name' => 'Answers',
            'headers' => ['question_id', 'question', 'answer', 'answers_count', 'percentage'],
            'rows' => $rows,
        ];
    }

    private function readmeSheet($version, int $versionId, $from, $to, $lang, int $playersCount): array
    {
        $periodo = ($from || $to) ? (($from ?: '...') . ' - ' . ($to ?: '...')) : 'tutto';

        return [
            'name' => 'README',
            'headers' => ['Export', 'Statistiche versione gioco/campagna'],
            'rows' => [
                ['Version ID', $versionId],
                ['Nome version', $version->name ?? ''],
                ['Formato', 'XLSX multi-sheet'],
                ['Generato il', date('Y-m-d H:i:s')],
                ['Periodo', $periodo],
                ['Filtro lingua', $lang ?: 'tutte'],
                ['Filtro sorgente', 'tutte'],
                ['Righe player', $playersCount],
                ['Note', 'Report con KPI, dati grezzi, aggregazioni, qualita dati e transazioni.'],
            ],
        ];
    }

    private function kpiSheet(array $kpis): array
    {
        return [
            'name' => 'KPIs',
            'headers' => ['KPI', 'Valore', 'Formula / origine'],
            'rows' => $kpis,
        ];
    }

    private function configSheet(array $definition, $from, $to, $lang): array
    {
        return [
            'name' => 'Configurazione_Richiesta',
            'headers' => ['Parametro', 'Valore'],
            'rows' => [
                ['format', $definition['format'] ?? 'xlsx'],
                ['report', 'full'],
                ['date_from', $from ?: ''],
                ['date_to', $to ?: ''],
                ['sheets', 'readme,kpis,configurazione_richiesta,players,events_summary,answers,transactions,data_quality'],
                ['players.columns', 'player_id,email,registered_at,language,utm_source,company,total_score,levels_completed,events_count,reward,status,marketing_optin,revenue'],
                ['events_summary.group_by', 'type,payload.language,payload.utm_source'],
                ['events_summary.metrics', 'count,unique_players,events_per_player,avg_score'],
                ['filters.payload.language', $lang ?: '*'],
                ['sort.players', 'total_score:desc'],
            ],
        ];
    }

    private function dataQualitySheet(array $rows): array
    {
        return [
            'name' => 'Data_Quality',
            'headers' => ['check', 'severity', 'occurrences', 'description'],
            'rows' => $rows,
        ];
    }

    // ===================================================== CALCOLI

    /** @return array<int,array> righe di data quality (riusate anche dai KPI) */
    private function dataQualityRows(int $versionId, $from, $to): array
    {
        $conn = $this->connection;
        $langExpr = $this->json('events.payload', 'language');

        $dupEmails = DB::connection($conn)->table('players')
            ->where('version_id', $versionId)->whereNotNull('email')
            ->select('email')->groupBy('email')->havingRaw('COUNT(*) > 1')->get()->count();

        $missingLang = DB::connection($conn)->table('events')->where('version_id', $versionId)
            ->whereRaw("$langExpr IS NULL")->count();

        $invalidOrder = DB::connection($conn)->table('events')
            ->join('players', 'players.id', '=', 'events.player_id')
            ->where('events.version_id', $versionId)
            ->where('events.type', 'complete')
            ->whereRaw('events.occurred_at < players.registered_at')
            ->count();

        $orphan = DB::connection($conn)->table('events')->where('version_id', $versionId)
            ->whereNotNull('player_id')
            ->whereNotIn('player_id', function ($q) use ($versionId) {
                $q->from('players')->where('version_id', $versionId)->select('id');
            })->count();

        $emptyPayload = DB::connection($conn)->table('events')->where('version_id', $versionId)
            ->where(function ($q) {
                $q->whereNull('payload')->orWhereRaw("payload = '{}'");
            })->count();

        return [
            ['duplicate_player_email', 'warning', $dupEmails, 'Player con la stessa email nella version'],
            ['missing_language', 'warning', $missingLang, 'Eventi senza payload.language'],
            ['invalid_event_order', 'error', $invalidOrder, 'Completamento avvenuto prima della registrazione'],
            ['orphan_event', 'error', $orphan, 'Eventi riferiti a player non presente in players'],
            ['empty_payload', 'info', $emptyPayload, 'Eventi con payload JSON vuoto'],
        ];
    }

    /** @return array<int,array> righe KPI [nome, valore, formula] */
    private function kpiRows(int $versionId, $from, $to, $lang, array $dataQuality): array
    {
        $conn = $this->connection;

        $players = $this->playersBaseQuery($versionId, $from, $to, $lang);
        $playersCount = (clone $players)->count();
        $scoreTotal = (int) (clone $players)->sum('total_score');
        $scoreAvg = $playersCount > 0 ? round($scoreTotal / $playersCount, 2) : 0;

        $completed = DB::connection($conn)->table('players')->where('players.version_id', $versionId)
            ->whereIn('players.id', function ($q) use ($versionId) {
                $q->from('events')->where('version_id', $versionId)
                    ->where('type', 'complete')->select('player_id');
            })->count();
        $completionRate = $playersCount > 0 ? round($completed / $playersCount, 4) : 0;

        $events = DB::connection($conn)->table('events')->where('version_id', $versionId);
        $this->applyDate($events, 'occurred_at', $from, $to);
        $eventsTotal = $events->count();

        $txQuery = DB::connection($conn)->table('transactions')->where('version_id', $versionId);
        $this->applyDate($txQuery, 'occurred_at', $from, $to);
        $txTotal = (clone $txQuery)->count();
        $txValue = round(((int) (clone $txQuery)->sum('amount')) / 100.0, 2);

        $dqErrors = 0;
        foreach ($dataQuality as $row) {
            if ($row[1] === 'error') {
                $dqErrors += (int) $row[2];
            }
        }

        return [
            ['Player esportati', $playersCount, 'Conteggio righe player'],
            ['Player completati', $completed, 'Player con almeno un evento complete'],
            ['Completion rate', $completionRate, 'Player completati / player esportati'],
            ['Score totale', $scoreTotal, 'Somma total_score'],
            ['Score medio', $scoreAvg, 'Media total_score'],
            ['Eventi totali', $eventsTotal, 'Conteggio eventi'],
            ['Transazioni totali', $txTotal, 'Conteggio transazioni'],
            ['Valore transazioni', $txValue, 'Somma amount (in valuta)'],
            ['Errori data quality', $dqErrors, 'Somma anomalie con severity error'],
        ];
    }

    /** Query base dei player filtrata (riusata da KPI e README). */
    private function playersBaseQuery(int $versionId, $from, $to, $lang)
    {
        $query = DB::connection($this->connection)->table('players')
            ->where('players.version_id', $versionId);
        $this->applyDate($query, 'players.registered_at', $from, $to);
        $this->applyLang($query, $this->json('players.payload', 'language'), $lang);

        return $query;
    }

    // ===================================================== HELPER

    /** Espressione SQL (driver-aware) per estrarre payload.<key>. */
    private function json(string $columnExpr, string $key): string
    {
        $path = '$."' . $key . '"';
        if ($this->driver === 'sqlite') {
            return "json_extract($columnExpr, '$path')";
        }
        return "JSON_UNQUOTE(JSON_EXTRACT($columnExpr, '$path'))";
    }

    private function applyDate($query, string $column, $from, $to): void
    {
        if ($from) {
            $query->where($column, '>=', $from . ' 00:00:00');
        }
        if ($to) {
            $query->where($column, '<=', $to . ' 23:59:59');
        }
    }

    private function applyLang($query, string $langExpr, $lang): void
    {
        if ($lang) {
            $query->whereRaw("$langExpr = ?", [$lang]);
        }
    }
}
