<?php

use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\IO\XerExporter;
use TeckFuture\P6Analyzer\IO\XerParser;

covers(XerExporter::class);

test('round-trip: parse → export → parse yields same data', function () {
    $parser = new XerParser;
    $original = $parser->parseFile($this->fixturePath());

    $exporter = new XerExporter;
    $exported = $exporter->export($original);

    $reparsed = $parser->parse($exported);

    expect($reparsed->getTable('TASK')->count())->toBe($original->getTable('TASK')->count())
        ->and($reparsed->getTable('PROJECT')->count())->toBe($original->getTable('PROJECT')->count());
});

test('it exports to a file and back', function () {
    $parser = new XerParser;
    $data = $parser->parseFile($this->fixturePath());

    $tmpFile = tempnam(sys_get_temp_dir(), 'xer_export_test_');
    $exporter = new XerExporter;
    $result = $exporter->exportToFile($data, $tmpFile);

    expect($result)->toBeTrue()
        ->and(file_exists($tmpFile))->toBeTrue();

    $reparsed = $parser->parseFile($tmpFile);
    expect($reparsed->getTable('TASK')->count())->toBe($data->getTable('TASK')->count());

    unlink($tmpFile);
});

// ---------------------------------------------------------------
// Exact String Output (Kills string concat, condition, and fallback mutations)
// ---------------------------------------------------------------

test('exports empty header exactly', function () {
    // IfNegated / RemoveNot on line 19
    $data = new ParsedXerData(header: '', tables: collect());
    $exporter = new XerExporter;

    // Mutated: it would include empty string then \n -> "\n"
    // Unmutated: it adds nothing, then implodes and adds \n -> "\n"
    // Wait, implode([], "\n") is "" + "\n" = "\n".
    expect($exporter->export($data))->toBe("\n");
});

test('exports header properly', function () {
    $data = new ParsedXerData(header: 'ERMHDR	8.0', tables: collect());
    $exporter = new XerExporter;

    // Kills ConcatRemoveLeft line 40 (removes \n at the end)
    expect($exporter->export($data))->toBe("ERMHDR\t8.0\n");
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
    ])."\n";

    // Kills Concat mutations on %T, %F, %R and final \n
    expect($exporter->export($data))->toBe($expected);
});

test('skips empty tables', function () {
    // Kills RemoveEarlyReturn line 24 (if isEmpty continue)
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
    ])."\n";

    expect((new XerExporter)->export($data))->toBe($expected);
});

test('row missing column falls back to empty string', function () {
    // Kills CoalesceRemoveLeft line 35 ($row[$header] ?? '')
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
    ])."\n";

    expect((new XerExporter)->export($data))->toBe($expected);
});
