<?php

namespace App\Services\Export;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

/**
 * Wrapper sottile su OpenSpout per scrivere file .xlsx in STREAMING.
 *
 * Perché OpenSpout e non PhpSpreadsheet?
 *   PhpSpreadsheet tiene l'intero foglio in memoria: 500k righe lo farebbero
 *   esplodere (GB di RAM). OpenSpout scrive riga per riga direttamente sul file
 *   (i .xlsx sono zip di XML), quindi l'uso di memoria resta ~costante a
 *   prescindere dal numero di righe. È la scelta obbligata per i volumi richiesti.
 */
class XlsxWriter
{
    /** @var \OpenSpout\Writer\WriterMultiSheetsAbstract */
    private $writer;

    /** @var bool il primo foglio è già aperto da OpenSpout */
    private $firstSheetUsed = false;

    /** @var array<string,bool> nomi di foglio già usati (devono essere unici) */
    private $usedSheetNames = [];

    public function open(string $absolutePath): void
    {
        $this->writer = WriterEntityFactory::createXLSXWriter();
        $this->writer->openToFile($absolutePath);
    }

    /**
     * Inizia un nuovo foglio con nome e intestazione.
     *
     * @param array<int,string> $headers
     */
    public function startSheet(string $name, array $headers): void
    {
        $sheet = $this->firstSheetUsed
            ? $this->writer->addNewSheetAndMakeItCurrent()
            : $this->writer->getCurrentSheet();

        $sheet->setName($this->safeSheetName($name));
        $this->firstSheetUsed = true;

        $this->addRow($headers);
    }

    /**
     * Aggiunge una riga di dati. I valori non scalari vengono serializzati a JSON.
     *
     * @param array<int,mixed> $values
     */
    public function addRow(array $values): void
    {
        $cells = array_map(function ($value) {
            if (is_array($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE);
            }
            if (is_bool($value)) {
                return $value ? 1 : 0;
            }
            return $value;
        }, array_values($values));

        $this->writer->addRow(WriterEntityFactory::createRowFromArray($cells));
    }

    public function close(): void
    {
        if ($this->writer) {
            $this->writer->close();
        }
    }

    /**
     * Excel impone: max 31 caratteri, niente []:*?/\ e nomi unici nel workbook.
     */
    private function safeSheetName(string $name): string
    {
        $clean = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', '_', $name);
        $clean = mb_substr($clean, 0, 31);
        if ($clean === '') {
            $clean = 'Sheet';
        }

        $candidate = $clean;
        $i = 1;
        while (isset($this->usedSheetNames[mb_strtolower($candidate)])) {
            $suffix = '_' . (++$i);
            $candidate = mb_substr($clean, 0, 31 - mb_strlen($suffix)) . $suffix;
        }

        $this->usedSheetNames[mb_strtolower($candidate)] = true;

        return $candidate;
    }
}
