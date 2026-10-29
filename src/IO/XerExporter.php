<?php

namespace TeckFuture\P6Analyzer\IO;

use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;

/**
 * Exports ParsedXerData back to the XER file format.
 *
 * The output is the header line, then a `%T` / `%F` / `%R` block for every table that has
 * rows, then the `%E` end marker. Tables without rows are left out.
 */
class XerExporter
{
    /**
     * Export ParsedXerData to a raw XER string.
     *
     * A table's `%F` line lists the columns of all its rows, in the order they first appear,
     * and a row without a value for a column gets an empty one.
     */
    public function export(ParsedXerData $data): string
    {
        $lines = [];

        if (! empty($data->header)) {
            $lines[] = $data->header;
        }

        foreach ($data->tables as $tableName => $rows) {
            if ($rows->isEmpty()) {
                continue;
            }

            $lines[] = "%T\t{$tableName}";

            $headers = $this->columns($rows);
            $lines[] = '%F'."\t".implode("\t", $headers);

            foreach ($rows as $row) {
                $rowValues = array_map(fn (int|string $header) => $row[$header] ?? '', $headers);
                $lines[] = '%R'."\t".implode("\t", $rowValues);
            }
        }

        $lines[] = '%E';

        return implode("\n", $lines)."\n";
    }

    /**
     * The columns of every row: the first row's, then each one a later row adds, in order of first appearance.
     *
     * @param  Collection<int, array<string, string>>  $rows
     * @return array<int, int|string>
     */
    private function columns(Collection $rows): array
    {
        $columns = [];

        foreach ($rows as $row) {
            foreach ($row as $column => $value) {
                $columns[$column] = true;
            }
        }

        return array_keys($columns);
    }

    /**
     * Export to a file on disk.
     */
    public function exportToFile(ParsedXerData $data, string $filePath): bool
    {
        return file_put_contents($filePath, $this->export($data)) !== false;
    }
}
