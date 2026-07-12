<?php

namespace TeckFuture\P6Analyzer\IO;

use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\Exceptions\XerParseException;

/**
 * Parses Primavera P6 XER files into a structured ParsedXerData object.
 *
 * Supports both file-based and string-based parsing. The XER format uses
 * tab-delimited lines with `%T` (table), `%F` (fields), and `%R` (rows) prefixes.
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
    /**
     * Parse an XER file into a ParsedXerData structure.
     *
     * @throws XerParseException If the file does not exist or cannot be read.
     */
    public function parseFile(string $filePath): ParsedXerData
    {
        if (! file_exists($filePath)) {
            throw new XerParseException("XER file not found at path: {$filePath}");
        }

        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new XerParseException("Failed to read XER file at path: {$filePath}");
        }

        return $this->parse($content);
    }

    /**
     * Parse raw XER content string into a ParsedXerData structure.
     */
    public function parse(string $content): ParsedXerData
    {
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
            }
        }

        return new ParsedXerData(
            header: $header,
            tables: $tables,
        );
    }
}
