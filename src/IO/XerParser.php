<?php

namespace TeckFuture\P6Analyzer\IO;

use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\Exceptions\XerParseException;

/**
 * Parses Primavera P6 XER files into a structured ParsedXerData object.
 *
 * Supports both file-based and string-based parsing. The XER format uses
 * tab-delimited lines with `%T` (table), `%F` (fields), and `%R` (rows) prefixes.
 *
 * P6 writes XER files as Windows-1252. Parsed values are always UTF-8: content that
 * is not valid UTF-8 is converted from Windows-1252, and a leading UTF-8 byte order
 * mark is dropped.
 *
 * @example
 * ```php
 * $parser = new XerParser();
 * $data = $parser->parseFile('/path/to/schedule.xer');
 * $activities = $data->getActivities();
 * ```
 */
class XerParser
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * Parse an XER file into a ParsedXerData structure.
     *
     * @throws XerParseException If the path is not a readable file (a directory is not one),
     *                           or the content is not a valid XER file.
     */
    public function parseFile(string $filePath): ParsedXerData
    {
        if (! is_file($filePath)) {
            throw new XerParseException("XER file not found at path: {$filePath}");
        }

        if (! is_readable($filePath)) {
            throw new XerParseException("Failed to read XER file at path: {$filePath}");
        }

        $content = @file_get_contents($filePath);

        if ($content === false) {
            throw new XerParseException("Failed to read XER file at path: {$filePath}");
        }

        return $this->parse($content);
    }

    /**
     * Parse raw XER content string into a ParsedXerData structure.
     *
     * @throws XerParseException If the content has neither an ERMHDR header nor any table.
     */
    public function parse(string $content): ParsedXerData
    {
        $content = $this->toUtf8($content);
        $content = str_replace("\r\n", "\n", $content);
        $lines = explode("\n", $content);

        $tables = collect();
        $header = '';
        $currentTable = null;
        $headers = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            if (str_starts_with($line, 'ERMHDR')) {
                $header = $line;

                continue;
            }

            if (str_starts_with($line, '%T')) {
                $parts = explode("\t", $line);
                $currentTable = trim($parts[1] ?? '');

                if (! empty($currentTable) && ! $tables->has($currentTable)) {
                    $tables->put($currentTable, collect());
                }
            } elseif ($currentTable && str_starts_with($line, '%F')) {
                $headers = explode("\t", substr($line, 3));
            } elseif ($currentTable && str_starts_with($line, '%R')) {
                $values = explode("\t", substr($line, 3));

                $values = array_pad($values, count($headers), '');

                if (count($values) > count($headers)) {
                    $values = array_slice($values, 0, count($headers));
                }

                $row = array_combine($headers, $values);
                $tables->get($currentTable)->push($row);
            } elseif (! $this->startsWithRecordPrefix($line)) {
                $this->appendContinuationLine($tables, $currentTable, $line);
            }
        }

        if ($header === '' && $tables->isEmpty()) {
            throw new XerParseException('Content is not a valid XER file: it has no ERMHDR header and no tables');
        }

        return new ParsedXerData(
            header: $header,
            tables: $tables,
        );
    }

    /**
     * Drop a leading UTF-8 byte order mark and convert Windows-1252 content to UTF-8.
     *
     * Content that is already valid UTF-8 is left as it is.
     */
    private function toUtf8(string $content): string
    {
        if (str_starts_with($content, self::UTF8_BOM)) {
            $content = substr($content, strlen(self::UTF8_BOM));
        }

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return $content;
    }

    /**
     * Whether the line opens a new XER record (header, table, fields, row or end marker).
     */
    private function startsWithRecordPrefix(string $line): bool
    {
        foreach (['ERMHDR', '%T', '%F', '%R', '%E'] as $prefix) {
            if (str_starts_with($line, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Append a continuation line of a multi-line row to the last field value of
     * the current table's most recent row.
     *
     * P6 writes values containing line breaks (for example CALENDAR.clndr_data)
     * across several physical lines. Lines seen before any row of a table exist
     * are ignored.
     *
     * @param  Collection<string, Collection<int, array<string, string>>>  $tables
     */
    private function appendContinuationLine(Collection $tables, ?string $currentTable, string $line): void
    {
        if (! $currentTable || ! $tables->has($currentTable)) {
            return;
        }

        $tableRows = $tables->get($currentTable);

        if ($tableRows->isEmpty()) {
            return;
        }

        $lastRowIndex = $tableRows->keys()->last();
        $lastRow = $tableRows->get($lastRowIndex);
        $lastField = array_key_last($lastRow);

        if ($lastField === null) {
            return;
        }

        $lastRow[$lastField] .= "\n".$line;
        $tableRows->put($lastRowIndex, $lastRow);
    }
}
