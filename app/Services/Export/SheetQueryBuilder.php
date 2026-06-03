<?php

namespace App\Services\Export;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Costruisce la query SQL di un singolo foglio a partire dalla sua definizione.
 *
 * Due modalità:
 *   - RIGHE       : elenco di colonne (eventualmente filtrate/ordinate).
 *   - AGGREGAZIONE: presenti `group_by` + `metrics` (es. conteggi per tipo/lingua).
 *
 * Tutto passa dal FieldResolver, quindi ogni colonna/filtro/sort è validato
 * contro la whitelist del dataset prima di entrare nell'SQL.
 */
class SheetQueryBuilder
{
    public const MODE_ROWS = 'rows';
    public const MODE_AGGREGATE = 'aggregate';

    /** @var string */
    private $connection;

    public function __construct(string $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array{mode:string,query:Builder,headers:array<int,string>}
     */
    public function build(Dataset $dataset, int $versionId, array $sheet, ?string $dateFrom, ?string $dateTo): array
    {
        $driver = DB::connection($this->connection)->getDriverName();
        $resolver = new FieldResolver($dataset, $driver);

        $isAggregate = !empty($sheet['group_by']) || !empty($sheet['metrics']);

        $query = DB::connection($this->connection)
            ->table($dataset->table)
            ->where('version_id', $versionId);

        $this->applyDateRange($query, $dataset, $dateFrom, $dateTo);
        $this->applyFilters($query, $resolver, $sheet['filters'] ?? []);

        return $isAggregate
            ? $this->buildAggregate($query, $resolver, $dataset, $sheet)
            : $this->buildRows($query, $resolver, $dataset, $sheet);
    }

    /**
     * Conta le righe che l'export produrrebbe (per stima progress e tetto max_rows).
     * Funziona anche in modalità aggregata (conteggio dei gruppi).
     */
    public function countRows(Builder $query): int
    {
        return (clone $query)->getCountForPagination();
    }

    // ----------------------------------------------------------------- RIGHE

    private function buildRows(Builder $query, FieldResolver $resolver, Dataset $dataset, array $sheet): array
    {
        $fields = !empty($sheet['columns']) ? $sheet['columns'] : $dataset->defaultColumns();

        $selects = [];
        foreach ($fields as $field) {
            $selects[] = DB::raw($resolver->selectExpression($field));
        }
        $query->select($selects);

        $this->applySort($query, $resolver, $sheet['sort'] ?? []);

        // Tiebreaker deterministico per uno streaming stabile.
        $query->orderBy($dataset->table . '.id');

        return [
            'mode' => self::MODE_ROWS,
            'query' => $query,
            'headers' => array_map([$resolver, 'alias'], $fields),
        ];
    }

    // ----------------------------------------------------------- AGGREGAZIONE

    private function buildAggregate(Builder $query, FieldResolver $resolver, Dataset $dataset, array $sheet): array
    {
        $groupBy = $sheet['group_by'] ?? [];
        $metrics = $sheet['metrics'] ?? ['count'];

        $selects = [];
        $headers = [];
        $groupExprs = [];

        foreach ($groupBy as $field) {
            $expr = $resolver->sqlExpression($field);
            $selects[] = DB::raw($expr . ' as `' . $resolver->alias($field) . '`');
            $groupExprs[] = DB::raw($expr);
            $headers[] = $resolver->alias($field);
        }

        foreach ($metrics as $metric) {
            [$sql, $alias] = $this->metricExpression($metric, $resolver, $dataset);
            $selects[] = DB::raw($sql . ' as `' . $alias . '`');
            $headers[] = $alias;
        }

        $query->select($selects)->groupBy($groupExprs);

        // Ordinamento opzionale sugli alias prodotti (MySQL/SQLite lo consentono).
        foreach ($sheet['sort'] ?? [] as $entry) {
            [$field, $dir] = $this->parseSort($entry);
            if (!in_array($field, $headers, true)) {
                throw new InvalidArgumentException("Sort '{$field}' non valido per un foglio aggregato.");
            }
            $query->orderByRaw('`' . $field . '` ' . $dir);
        }

        return [
            'mode' => self::MODE_AGGREGATE,
            'query' => $query,
            'headers' => $headers,
        ];
    }

    /**
     * Traduce una metrica in espressione SQL + alias.
     * Supporta: count, unique_players, sum:<campo>, avg:<campo>, min:<campo>, max:<campo>.
     *
     * @return array{0:string,1:string}
     */
    private function metricExpression(string $metric, FieldResolver $resolver, Dataset $dataset): array
    {
        if ($metric === 'count') {
            return ['COUNT(*)', 'count'];
        }

        if ($metric === 'unique_players') {
            return ['COUNT(DISTINCT `' . $dataset->playerColumn . '`)', 'unique_players'];
        }

        if (strpos($metric, ':') !== false) {
            [$fn, $field] = explode(':', $metric, 2);
            $fn = strtolower($fn);
            if (!in_array($fn, ['sum', 'avg', 'min', 'max'], true)) {
                throw new InvalidArgumentException("Funzione di metrica non supportata: '{$fn}'.");
            }
            $expr = $resolver->sqlExpression($field);
            $alias = $fn . '_' . str_replace('.', '_', $field);

            return [strtoupper($fn) . '(' . $expr . ')', $alias];
        }

        throw new InvalidArgumentException("Metrica non supportata: '{$metric}'.");
    }

    // -------------------------------------------------------------- COMMON

    private function applyDateRange(Builder $query, Dataset $dataset, ?string $dateFrom, ?string $dateTo): void
    {
        if (!$dataset->dateColumn) {
            return;
        }
        if ($dateFrom) {
            $query->where($dataset->dateColumn, '>=', $dateFrom . ' 00:00:00');
        }
        if ($dateTo) {
            $query->where($dataset->dateColumn, '<=', $dateTo . ' 23:59:59');
        }
    }

    private function applyFilters(Builder $query, FieldResolver $resolver, array $filters): void
    {
        foreach ($filters as $field => $value) {
            $expr = DB::raw($resolver->sqlExpression($field));

            if (is_array($value)) {
                $query->whereIn($expr, $value);
            } elseif ($value === null) {
                $query->whereNull($expr);
            } else {
                $query->where($expr, '=', $value);
            }
        }
    }

    private function applySort(Builder $query, FieldResolver $resolver, array $sort): void
    {
        foreach ($sort as $entry) {
            [$field, $dir] = $this->parseSort($entry);
            $query->orderByRaw($resolver->sqlExpression($field) . ' ' . $dir);
        }
    }

    /** @return array{0:string,1:string} [campo, direzione] */
    private function parseSort(string $entry): array
    {
        $parts = explode(':', $entry, 2);
        $field = $parts[0];
        $dir = strtolower($parts[1] ?? 'asc');

        if (!in_array($dir, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException("Direzione di sort non valida: '{$dir}'.");
        }

        return [$field, $dir];
    }
}
