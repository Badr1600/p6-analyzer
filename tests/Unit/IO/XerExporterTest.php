<?php

use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\IO\ScheduleUpdater;
use TeckFuture\P6Analyzer\IO\XerExporter;
use TeckFuture\P6Analyzer\IO\XerParser;

covers(XerExporter::class);

test('round-trip: parse → export → parse preserves the TASK and PROJECT row counts', function () {
    $parser = new XerParser;
    $original = $parser->parseFile($this->fixturePath());

    $exporter = new XerExporter;
    $exported = $exporter->export($original);

    $reparsed = $parser->parse($exported);

    expect($reparsed->getTable('TASK')->count())->toBe($original->getTable('TASK')->count())
        ->and($reparsed->getTable('PROJECT')->count())->toBe($original->getTable('PROJECT')->count());
});

test('round-trip: parse → export → parse gives the same header and every row of every table', function () {
    $parser = new XerParser;
    $original = $parser->parseFile($this->fixturePath());

    $reparsed = $parser->parse((new XerExporter)->export($original));

    // Empty tables are not exported, so the fixture's row-less RSRCCURVDATA is the only table that drops out
    $exportedTables = $original->tables->filter(fn ($rows) => $rows->isNotEmpty());

    expect($reparsed->header)->toBe($original->header)
        ->and($reparsed->tables->toArray())->toBe($exportedTables->toArray())
        ->and($original->tables->keys()->diff($reparsed->tables->keys())->values()->all())->toBe(['RSRCCURVDATA']);
});

test('it exports to a file and back', function () {
    $parser = new XerParser;
    $data = $parser->parseFile($this->fixturePath());

    $tmpFile = tempnam(sys_get_temp_dir(), 'xer_export_test_');

    try {
        $exporter = new XerExporter;
        $result = $exporter->exportToFile($data, $tmpFile);

        expect($result)->toBeTrue()
            ->and(file_get_contents($tmpFile))->toBe($exporter->export($data));

        $reparsed = $parser->parseFile($tmpFile);
        expect($reparsed->getTable('TASK')->count())->toBe($data->getTable('TASK')->count());
    } finally {
        unlink($tmpFile);
    }
});

// ---------------------------------------------------------------
// Exact string output
// ---------------------------------------------------------------

test('exporting an empty document yields only the %E end marker', function () {
    $data = new ParsedXerData(header: '', tables: collect());
    $exporter = new XerExporter;

    expect($exporter->export($data))->toBe("%E\n");
});

test('exports header properly', function () {
    $data = new ParsedXerData(header: 'ERMHDR	8.0', tables: collect());
    $exporter = new XerExporter;

    // The header line is terminated by a newline and followed by the %E end marker
    expect($exporter->export($data))->toBe("ERMHDR\t8.0\n%E\n");
});

test('exports tables and rows properly', function () {
    $data = new ParsedXerData(
        header: 'ERMHDR	8.0',
        tables: collect([
            'PROJ' => collect([['proj_id' => '1', 'name' => 'Project A']]),
        ])
    );
    $exporter = new XerExporter;

    $expected = implode("\n", [
        'ERMHDR	8.0',
        '%T	PROJ',
        '%F	proj_id	name',
        '%R	1	Project A',
        '%E',
    ])."\n";

    // Table, field and row lines carry their %T / %F / %R prefixes, and the output ends with an %E line and a newline
    expect($exporter->export($data))->toBe($expected);
});

test('skips empty tables', function () {
    $data = new ParsedXerData(
        header: '',
        tables: collect([
            'EMPTY' => collect(), // Empty table
            'PROJ' => collect([['id' => '1']]),
        ])
    );

    $expected = implode("\n", [
        '%T	PROJ',
        '%F	id',
        '%R	1',
        '%E',
    ])."\n";

    expect((new XerExporter)->export($data))->toBe($expected);
});

test('the export ends with exactly one %E line', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());

    $lines = array_values(array_filter(
        explode("\n", (new XerExporter)->export($data)),
        fn (string $line): bool => $line !== '',
    ));

    expect(end($lines))->toBe('%E')
        ->and(array_count_values($lines)['%E'])->toBe(1);
});

test('the field line is the ordered union of the keys of every row', function () {
    $data = new ParsedXerData(
        header: '',
        tables: collect([
            'TASK' => collect([
                ['task_id' => '1', 'task_name' => 'First'],
                ['task_id' => '2', 'task_name' => 'Second', 'user_field' => 'x'],
                ['task_id' => '3', 'extra' => 'y'],
            ]),
        ])
    );

    $expected = implode("\n", [
        "%T\tTASK",
        "%F\ttask_id\ttask_name\tuser_field\textra",
        "%R\t1\tFirst\t\t",
        "%R\t2\tSecond\tx\t",
        "%R\t3\t\t\ty",
        '%E',
    ])."\n";

    expect((new XerExporter)->export($data))->toBe($expected);
});

test('a column that the ScheduleUpdater adds to one row survives an export and a re-parse', function () {
    $parser = new XerParser;
    $data = $parser->parseFile($this->fixturePath());
    $lastTaskId = $data->getTable('TASK')->last()['task_id'];

    (new ScheduleUpdater)->updateActivity($data, $lastTaskId, ['custom_flag' => 'Y']);

    $tasks = $parser->parse((new XerExporter)->export($data))->getTable('TASK');

    expect($tasks->first())->toHaveKey('custom_flag')
        ->and($tasks->first()['custom_flag'])->toBe('')
        ->and($tasks->last()['custom_flag'])->toBe('Y')
        ->and($tasks->pluck('custom_flag')->filter()->count())->toBe(1);
});

test('row missing column falls back to empty string', function () {
    // Force a row to not have a column that is present in the first row
    $data = new ParsedXerData(
        header: '',
        tables: collect([
            'PROJ' => collect([
                ['id' => '1', 'name' => 'P1'],  // First row defines headers: id, name
                ['id' => '2'],                  // Second row missing 'name'
            ]),
        ])
    );

    $expected = implode("\n", [
        '%T	PROJ',
        '%F	id	name',
        '%R	1	P1',
        '%R	2	',     // Second row has empty string for name
        '%E',
    ])."\n";

    expect((new XerExporter)->export($data))->toBe($expected);
});
