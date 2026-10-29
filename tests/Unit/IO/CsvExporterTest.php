<?php

use TeckFuture\P6Analyzer\IO\CsvExporter;

/**
 * Read CSV text back into rows, the way a spreadsheet would.
 *
 * @return array<int, array<int, string|null>>
 */
function parseCsvOutput(string $csv): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);

    $rows = [];
    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($stream);

    return $rows;
}

test('it exports rows to CSV string', function () {
    $rows = collect([
        ['id' => '1', 'name' => 'Task A', 'cost' => '100'],
        ['id' => '2', 'name' => 'Task B', 'cost' => '200'],
    ]);

    $csv = (new CsvExporter)->export($rows);

    expect($csv)->toContain('id,name,cost')
        ->and($csv)->toContain('1,"Task A",100')
        ->and(substr_count($csv, "\n"))->toBeGreaterThanOrEqual(2);
});

test('it returns empty string for empty collection', function () {
    $csv = (new CsvExporter)->export(collect());

    expect($csv)->toBe('');
});

test('it escapes commas in values', function () {
    $rows = collect([
        ['name' => 'Task, with comma', 'desc' => 'ok'],
    ]);

    $csv = (new CsvExporter)->export($rows);

    expect($csv)->toContain('"Task, with comma"');
});

// ---------------------------------------------------------------
// Formula-injection guard
// ---------------------------------------------------------------

test('it prefixes a single quote to string cells that start with a formula character', function (string $value) {
    $csv = (new CsvExporter)->export(collect([['note' => $value]]));

    expect(parseCsvOutput($csv))->toBe([['note'], ["'".$value]]);
})->with([
    'equals' => ['=1+1'],
    'plus' => ['+1+1'],
    'minus' => ['-1+1'],
    'at sign' => ['@SUM(A1:A9)'],
    'tab' => ["\t=1+1"],
    'carriage return' => ["\r=1+1"],
    'hyperlink with quotes' => ['=HYPERLINK("http://example.com","click")'],
    'a lone minus' => ['-'],
]);

test('it applies the same guard to header cells', function () {
    $csv = (new CsvExporter)->export(collect([['=cmd' => 'a', '@x' => 'b', 'plain' => 'c']]));

    expect(parseCsvOutput($csv))->toBe([["'=cmd", "'@x", 'plain'], ['a', 'b', 'c']]);
});

test('it leaves numeric values alone, signed ones included', function (string $value) {
    $csv = (new CsvExporter)->export(collect([['qty' => $value]]));

    expect(parseCsvOutput($csv))->toBe([['qty'], [$value]]);
})->with(['-16.0', '+5', '-5', '-0.5', '+.5', '-1e3']);

test('it leaves text that only contains a formula character untouched', function (string $value) {
    $csv = (new CsvExporter)->export(collect([['note' => $value]]));

    expect(parseCsvOutput($csv))->toBe([['note'], [$value]]);
})->with(['a=b', '1+1', 'user@example.com', 'Phase 2 - civil', 'Plain']);

test('it leaves empty cells and cells that are not strings alone', function () {
    $csv = (new CsvExporter)->export(collect([['qty' => -5, 'rate' => -1.5, 'note' => null, 'empty' => '']]));

    expect(parseCsvOutput($csv))->toBe([['qty', 'rate', 'note', 'empty'], ['-5', '-1.5', '', '']]);
});

test('the guard can be switched off', function () {
    $rows = collect([['=cmd' => '=1+1', 'b' => '@x', 'c' => "\t=1", 'd' => '-16.0']]);

    $csv = (new CsvExporter(escapeFormulas: false))->export($rows);

    expect(parseCsvOutput($csv))->toBe([['=cmd', 'b', 'c', 'd'], ['=1+1', '@x', "\t=1", '-16.0']]);
});

test('the guard also applies when exporting to a file', function () {
    $path = tempnam(sys_get_temp_dir(), 'csv_export_test_');

    try {
        $written = (new CsvExporter)->exportToFile(collect([['note' => '=1+1']]), $path);

        expect($written)->toBeTrue()
            ->and(file_get_contents($path))->toBe("note\n'=1+1\n");
    } finally {
        unlink($path);
    }
});

// ---------------------------------------------------------------
// RFC 4180 output
// ---------------------------------------------------------------

test('a value with a quote and a line break round-trips through str_getcsv', function (bool $escapeFormulas) {
    $value = "He said \"stop\",\nthen left";

    $csv = (new CsvExporter(escapeFormulas: $escapeFormulas))->export(collect([['id' => '1', 'note' => $value]]));

    expect(str_getcsv(substr($csv, strlen("id,note\n")), ',', '"', ''))->toBe(['1', $value]);
})->with([
    'guard on' => [true],
    'guard off' => [false],
]);

test('a guarded value with a quote and a line break keeps its content after the prefix', function () {
    $value = "=A1&\"x\"\nsecond line";

    $csv = (new CsvExporter)->export(collect([['note' => $value]]));

    expect(str_getcsv(substr($csv, strlen("note\n")), ',', '"', ''))->toBe(["'".$value]);
});

test('a backslash before a quote does not break the quoting', function () {
    $value = 'C:\\"Schedules"\\final';

    $csv = (new CsvExporter)->export(collect([['path' => $value]]));

    expect(parseCsvOutput($csv))->toBe([['path'], [$value]]);
});

test('it raises no deprecation while exporting', function () {
    $deprecations = [];
    set_error_handler(function (int $level, string $message) use (&$deprecations): bool {
        $deprecations[] = $message;

        return true;
    }, E_DEPRECATED | E_USER_DEPRECATED);

    try {
        (new CsvExporter)->export(collect([['id' => '1', 'name' => 'Task A'], ['id' => '2', 'name' => '=Task B']]));
    } finally {
        restore_error_handler();
    }

    expect($deprecations)->toBe([]);
});
