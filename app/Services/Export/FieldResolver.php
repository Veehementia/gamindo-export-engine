<?php

namespace App\Services\Export;

use InvalidArgumentException;

/**
 * Traduce un riferimento di campo (proveniente dal payload del client) in
 * un'espressione SQL valida, applicando la whitelist del Dataset.
 *
 * Un campo può essere:
 *   - una colonna esposta dal dataset (es. "email", "player_id")
 *   - un path dentro il JSON `payload` (es. "payload.language")
 *
 * Qualunque altra cosa solleva un'eccezione (=> 422 lato API).
 */
class FieldResolver
{
    /** @var Dataset */
    private $dataset;

    /** @var string driver DB ("mysql" | "sqlite") */
    private $driver;

    public function __construct(Dataset $dataset, string $driver)
    {
        $this->dataset = $dataset;
        $this->driver = $driver;
    }

    /**
     * Espressione SQL grezza (senza alias) usabile in WHERE / ORDER BY / GROUP BY.
     */
    public function sqlExpression(string $field): string
    {
        // Path JSON: "payload.x.y"
        if (SqlJsonPath::isJsonPath($field)) {
            [$column, $segments] = SqlJsonPath::parse($field);

            if (!$this->dataset->hasPayload || $column !== 'payload') {
                throw new InvalidArgumentException(
                    "Il campo JSON '{$field}' non è consentito sul dataset '{$this->dataset->key}'."
                );
            }

            return SqlJsonPath::expression($this->driver, $column, $segments);
        }

        // Colonna semplice: deve essere nella whitelist.
        if (!array_key_exists($field, $this->dataset->columns)) {
            throw new InvalidArgumentException(
                "Campo '{$field}' non consentito sul dataset '{$this->dataset->key}'. " .
                'Consentiti: ' . implode(', ', $this->dataset->defaultColumns()) . ', payload.*'
            );
        }

        return '`' . $this->dataset->columns[$field] . '`';
    }

    /**
     * Alias di output (intestazione di colonna nel foglio). Per i campi semplici
     * usiamo il nome esposto; per i path JSON il path stesso (es. "payload.language").
     */
    public function alias(string $field): string
    {
        return $field;
    }

    /** Espressione SELECT completa "<expr> as `<alias>`". */
    public function selectExpression(string $field): string
    {
        return $this->sqlExpression($field) . ' as `' . $this->alias($field) . '`';
    }
}
