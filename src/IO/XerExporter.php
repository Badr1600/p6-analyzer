<?php

namespace TeckFuture\P6Analyzer\IO;

use TeckFuture\P6Analyzer\DTOs\ParsedXerData;

/**
 * Exports ParsedXerData back to the XER file format.
 */
class XerExporter
{
    /**
     * Export ParsedXerData to a raw XER string.
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

            $firstRow = $rows->first();
            $headers = array_keys($firstRow);
            $lines[] = '%F'."\t".implode("\t", $headers);

            foreach ($rows as $row) {
                $rowValues = array_map(fn (string $header) => $row[$header] ?? '', $headers);
                $lines[] = '%R'."\t".implode("\t", $rowValues);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Export to a file on disk.
     */
    public function exportToFile(ParsedXerData $data, string $filePath): bool
    {
        return file_put_contents($filePath, $this->export($data)) !== false;
    }
}
