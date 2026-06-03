<?php

namespace App\Services\Export;

use InvalidArgumentException;

/**
 * Traduce un riferimento "payload.foo.bar" in un'espressione SQL che estrae il
 * valore dal campo JSON, in modo SICURO e indipendente dal driver.
 *
 * Sicurezza: i segmenti del path sono validati con una whitelist di caratteri
 * (lettere, cifre, underscore). Non finiscono mai concatenati grezzi in SQL se
 * non dopo questo controllo, quindi non c'è spazio per SQL injection via JSON path.
 *
 * Differenze tra driver:
 *  - MySQL  : JSON_UNQUOTE(JSON_EXTRACT(`col`, '$."a"."b"'))  -> scalare senza apici
 *  - SQLite : json_extract(`col`, '$."a"."b"')                -> già scalare
 */
class SqlJsonPath
{
    /** Indica se il riferimento è un path JSON (contiene un punto). */
    public static function isJsonPath(string $field): bool
    {
        return strpos($field, '.') !== false;
    }

    /**
     * Estrae nome colonna e path dal riferimento "colonna.a.b".
     * Per i nostri dataset la colonna JSON è sempre "payload".
     *
     * @return array{0:string,1:array<int,string>} [colonna, segmenti]
     */
    public static function parse(string $field): array
    {
        $parts = explode('.', $field);
        $column = array_shift($parts);

        foreach (array_merge([$column], $parts) as $segment) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $segment)) {
                throw new InvalidArgumentException("Segmento di path JSON non valido: '{$segment}'.");
            }
        }

        return [$column, $parts];
    }

    /**
     * Costruisce l'espressione SQL di estrazione per il driver indicato.
     *
     * @param array<int,string> $segments
     */
    public static function expression(string $driver, string $column, array $segments): string
    {
        $jsonPath = '$';
        foreach ($segments as $segment) {
            $jsonPath .= '."' . $segment . '"';
        }

        $col = '`' . $column . '`';

        if ($driver === 'sqlite') {
            return "json_extract({$col}, '{$jsonPath}')";
        }

        // MySQL / MariaDB
        return "JSON_UNQUOTE(JSON_EXTRACT({$col}, '{$jsonPath}'))";
    }
}
