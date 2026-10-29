<?php

namespace TeckFuture\P6Analyzer\IO;

use Illuminate\Support\Collection;

/**
 * Exports parsed XER data to CSV format.
 *
 * The output follows RFC 4180: a double quote inside a value is doubled and nothing is
 * backslash-escaped.
 *
 * A spreadsheet runs a cell that starts with `=`, `+`, `-` or `@` as a formula, and some do the same
 * for a cell that starts with a tab or a carriage return. Such a string cell gets a single quote in
 * front, which makes the spreadsheet show it as text. Numeric strings (`-16.0`, `+5`) are left
 * alone. The guard covers header cells too.
 */
class CsvExporter
{
    /**
     * @param  bool  $escapeFormulas  Prefix a string cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage
     *                                return, and is not numeric, with a single quote. Turn it off to get the
     *                                values exactly as they are, for code that reads the CSV rather than a spreadsheet.
     */
    public function __construct(private bool $escapeFormulas = true) {}

    /**
     * Export a collection of rows (associative arrays) to a CSV string.
     *
     * @param  Collection<int, array<string, string>>  $rows
     */
    public function export(Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return '';
        }

        $output = fopen('php://temp', 'r+');
        $headers = array_keys($rows->first());
        $this->writeRow($output, $headers);

        foreach ($rows as $row) {
            $values = array_map(fn (string $header) => $row[$header] ?? '', $headers);
            $this->writeRow($output, $values);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Export to a CSV file on disk.
     *
     * @param  Collection<int, array<string, string>>  $rows
     */
    public function exportToFile(Collection $rows, string $filePath): bool
    {
        return file_put_contents($filePath, $this->export($rows)) !== false;
    }

    /**
     * Write one row to the stream.
     *
     * @param  resource  $stream
     * @param  array<int, mixed>  $cells
     */
    private function writeRow($stream, array $cells): void
    {
        if ($this->escapeFormulas) {
            $cells = array_map($this->guardFormula(...), $cells);
        }

        // The empty escape character gives RFC 4180 output, and PHP 8.4 deprecates leaving it unset
        fputcsv($stream, $cells, ',', '"', '');
    }

    /**
     * Prefix a string that a spreadsheet would run as a formula with a single quote.
     */
    private function guardFormula(mixed $cell): mixed
    {
        if (is_string($cell) && $cell !== '' && str_contains("=+-@\t\r", $cell[0]) && ! is_numeric($cell)) {
            return "'".$cell;
        }

        return $cell;
    }
}
