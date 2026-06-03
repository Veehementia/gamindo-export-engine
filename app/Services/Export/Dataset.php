<?php

namespace App\Services\Export;

/**
 * Descrittore immutabile di una sorgente dati esportabile (players, events, ...).
 *
 * Funge da WHITELIST: solo le colonne qui dichiarate (più i path dentro `payload`,
 * se `hasPayload`) possono comparire in select/filtri/sort/group. Tutto ciò che
 * non rientra viene rifiutato con 422. È la difesa centrale contro SQL injection
 * e contro query arbitrarie sul DB.
 */
class Dataset
{
    /** @var string chiave logica (es. "events") */
    public $key;

    /** @var string nome tabella reale */
    public $table;

    /** @var array<string,string> mappa: nome esposto al client => colonna reale */
    public $columns;

    /** @var string|null colonna data usata per date_from/date_to */
    public $dateColumn;

    /** @var bool la tabella ha una colonna JSON `payload` interrogabile */
    public $hasPayload;

    /** @var string colonna che identifica il player (per la metrica unique_players) */
    public $playerColumn;

    public function __construct(
        string $key,
        string $table,
        array $columns,
        ?string $dateColumn,
        bool $hasPayload,
        string $playerColumn = 'player_id'
    ) {
        $this->key = $key;
        $this->table = $table;
        $this->columns = $columns;
        $this->dateColumn = $dateColumn;
        $this->hasPayload = $hasPayload;
        $this->playerColumn = $playerColumn;
    }

    /** Colonne di default se il client non ne specifica nessuna. */
    public function defaultColumns(): array
    {
        return array_keys($this->columns);
    }
}
