<?php

namespace TeckFuture\P6Analyzer\IO;

use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;

/**
 * Exports ParsedXerData back to the XER file format.
 *
 * The output is the header line, then a `%T` / `%F` / `%R` block for every table that has
 * rows, then the `%E` end marker. Tables without rows are left out.
 *
 * XerParser reads P6's Windows-1252 files as UTF-8, so the output is converted back to
 * Windows-1252, the encoding P6 expects. A character Windows-1252 has no byte for is
 * written as `?`.
 */
class XerExporter
{
    /**
     * Export ParsedXerData to a raw XER string, encoded as Windows-1252.
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

        return mb_convert_encoding(implode("\n", $lines)."\n", 'Windows-1252', 'UTF-8');
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
     *
     * The content is written to a temporary file next to the target and moved over it, so a
     * reader never sees a half-written file and a failed write leaves an existing file as it was.
     * The file is replaced, not rewritten: the new file keeps the old one's permissions, and a
     * symbolic link is followed. The path must name a regular file, existing or new, in a writable
     * directory: a directory, a device, a pipe and a stream wrapper such as `php://output` all fail.
     *
     * @return bool False if the file could not be written; no PHP warning is raised.
     */
    public function exportToFile(ParsedXerData $data, string $filePath): bool
    {
        if ($filePath === '') {
            return false;
        }

        $xer = $this->export($data);
        $target = realpath($filePath) ?: $filePath;
        $temporary = dirname($target).DIRECTORY_SEPARATOR.'.xer-'.bin2hex(random_bytes(8)).'.tmp';

        // A failure is reported through the return value alone
        set_error_handler(static fn (): bool => true);

        try {
            // Moving the temporary file over anything but a writable regular file would replace it
            if (file_exists($target) && (! is_file($target) || ! is_writable($target))) {
                return false;
            }

            if (file_put_contents($temporary, $xer) !== strlen($xer)) {
                return false;
            }

            if (is_file($target)) {
                chmod($temporary, fileperms($target) & 0777);
            }

            return rename($temporary, $target);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }

            restore_error_handler();
        }
    }
}
