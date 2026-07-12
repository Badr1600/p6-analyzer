<?php

use TeckFuture\P6Analyzer\IO\XerExporter;
use TeckFuture\P6Analyzer\IO\XerParser;

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
