<?php

use TeckFuture\P6Analyzer\IO\CsvExporter;

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
