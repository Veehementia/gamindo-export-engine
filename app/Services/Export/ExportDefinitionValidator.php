<?php

namespace App\Services\Export;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Valida e normalizza la definizione di un export PRIMA di salvarla in coda.
 *
 * Filosofia "fail fast": invece di lasciare che un errore (colonna inesistente,
 * filtro su campo non consentito, ...) faccia fallire il job ore dopo, facciamo
 * un "dry build" di ogni foglio già nella richiesta HTTP, così il client riceve
 * subito un 422 con il dettaglio dell'errore.
 */
class ExportDefinitionValidator
{
    /** Formati di output supportati. */
    private const FORMATS = ['xlsx'];

    /**
     * @return array la definizione normalizzata
     * @throws ValidationException
     */
    public function validate(array $definition): array
    {
        $errors = [];

        $format = $definition['format'] ?? 'xlsx';
        if (!in_array($format, self::FORMATS, true)) {
            $errors['format'][] = 'Formato non supportato. Ammessi: ' . implode(', ', self::FORMATS) . '.';
        }

        $dateFrom = $definition['date_from'] ?? null;
        $dateTo = $definition['date_to'] ?? null;
        foreach (['date_from' => $dateFrom, 'date_to' => $dateTo] as $key => $value) {
            if ($value !== null && !$this->isValidDate($value)) {
                $errors[$key][] = 'Formato data non valido (atteso YYYY-MM-DD).';
            }
        }
        if ($dateFrom && $dateTo && $this->isValidDate($dateFrom) && $this->isValidDate($dateTo) && $dateFrom > $dateTo) {
            $errors['date_from'][] = 'date_from non può essere successiva a date_to.';
        }

        $sheets = $definition['sheets'] ?? [];

        // Modalità REPORT: nessun foglio custom (o report=full esplicito) => generiamo
        // il report completo "full". Non serve validare fogli/colonne.
        $isReport = ($definition['report'] ?? null) === 'full'
            || !is_array($sheets) || count($sheets) === 0;

        if ($isReport) {
            if (!empty($errors)) {
                throw ValidationException::withMessages($errors);
            }
            return [
                'format' => $format,
                'report' => 'full',
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'filters' => is_array($definition['filters'] ?? null) ? $definition['filters'] : [],
                'sheets' => [],
            ];
        }

        // Dry build di ogni foglio (validazione campi/sorgenti sulla whitelist).
        $builder = new SheetQueryBuilder(DB::getDefaultConnection());
        foreach ($sheets as $i => $sheet) {
            $key = "sheets.$i";

            if (empty($sheet['name'])) {
                $errors[$key . '.name'][] = 'Il nome del foglio è obbligatorio.';
                continue;
            }

            try {
                $source = DatasetRegistry::resolveSource($sheet);
                $dataset = DatasetRegistry::get($source);
                // versionId fittizio: ci interessa solo che la costruzione SQL non sollevi errori.
                $builder->build($dataset, 0, $sheet, $dateFrom, $dateTo);
            } catch (InvalidArgumentException $e) {
                $errors[$key][] = $e->getMessage();
            } catch (Throwable $e) {
                $errors[$key][] = 'Definizione del foglio non valida: ' . $e->getMessage();
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'format' => $format,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'sheets' => array_values($sheets),
        ];
    }

    private function isValidDate(string $value): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value;
    }
}
