<?php

namespace App\Services\Export;

use InvalidArgumentException;

/**
 * Registro di tutte le sorgenti dati esportabili. Aggiungere un nuovo dataset
 * (o una nuova colonna esponibile) si fa SOLO qui: il resto del motore è generico.
 */
class DatasetRegistry
{
    /** @var array<string,Dataset>|null */
    private static $datasets = null;

    /** @return array<string,Dataset> */
    public static function all(): array
    {
        if (self::$datasets !== null) {
            return self::$datasets;
        }

        return self::$datasets = [
            'players' => new Dataset(
                'players',
                'players',
                [
                    'id' => 'id',
                    'player_id' => 'external_id', // alias comodo: nel foglio players "player_id" = external_id
                    'external_id' => 'external_id',
                    'email' => 'email',
                    'registered_at' => 'registered_at',
                    'total_score' => 'total_score',
                    'created_at' => 'created_at',
                ],
                'registered_at',
                true,
                'id'
            ),

            'events' => new Dataset(
                'events',
                'events',
                [
                    'id' => 'id',
                    'player_id' => 'player_id',
                    'type' => 'type',
                    'occurred_at' => 'occurred_at',
                    'created_at' => 'created_at',
                ],
                'occurred_at',
                true,
                'player_id'
            ),

            'transactions' => new Dataset(
                'transactions',
                'transactions',
                [
                    'id' => 'id',
                    'player_id' => 'player_id',
                    'amount' => 'amount',
                    'currency' => 'currency',
                    'status' => 'status',
                    'occurred_at' => 'occurred_at',
                ],
                'occurred_at',
                true,
                'player_id'
            ),

            'answers' => new Dataset(
                'answers',
                'answers',
                [
                    'id' => 'id',
                    'player_id' => 'player_id',
                    'question_id' => 'question_id',
                    'answer' => 'answer',
                    'is_correct' => 'is_correct',
                    'occurred_at' => 'occurred_at',
                ],
                'occurred_at',
                true,
                'player_id'
            ),

            'rewards' => new Dataset(
                'rewards',
                'rewards',
                [
                    'id' => 'id',
                    'player_id' => 'player_id',
                    'type' => 'type',
                    'value' => 'value',
                    'occurred_at' => 'occurred_at',
                ],
                'occurred_at',
                true,
                'player_id'
            ),
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    public static function get(string $key): Dataset
    {
        if (!self::has($key)) {
            throw new InvalidArgumentException("Dataset sconosciuto: '{$key}'.");
        }

        return self::all()[$key];
    }

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Determina la sorgente di un foglio: usa `source` se fornito, altrimenti
     * deduce dal nome del foglio (gestendo il suffisso "_summary" per i fogli
     * aggregati, es. "events_summary" -> dataset "events").
     */
    public static function resolveSource(array $sheet): string
    {
        if (!empty($sheet['source'])) {
            return $sheet['source'];
        }

        $name = $sheet['name'] ?? '';
        if (self::has($name)) {
            return $name;
        }

        $stripped = preg_replace('/_summary$/', '', $name);
        if (self::has($stripped)) {
            return $stripped;
        }

        throw new InvalidArgumentException(
            "Impossibile determinare la sorgente del foglio '{$name}'. Specifica 'source'."
        );
    }
}
