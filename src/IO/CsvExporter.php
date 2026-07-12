<?php

namespace TeckFuture\P6Analyzer\IO;

use Illuminate\Support\Collection;

/**
 * Exports parsed XER data to CSV format.
 */
class CsvExporter
{
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
        fputcsv($output, $headers);

        foreach ($rows as $row) {
            $values = array_map(fn (string $header) => $row[$header] ?? '', $headers);
            fputcsv($output, $values);
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
}
