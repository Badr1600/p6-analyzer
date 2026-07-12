<?php

use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\Exceptions\XerParseException;
use TeckFuture\P6Analyzer\IO\XerParser;

test('it parses an XER file into ParsedXerData', function () {
    $parser = new XerParser;
    $data = $parser->parseFile($this->fixturePath());

    expect($data)->toBeInstanceOf(ParsedXerData::class)
        ->and($data->header)->toStartWith('ERMHDR')
        ->and($data->tables)->not->toBeEmpty();
});

test('it extracts TASK table with activities', function () {
    $parser = new XerParser;
    $data = $parser->parseFile($this->fixturePath());

    $tasks = $data->getTable('TASK');

    expect($tasks)->not->toBeEmpty()
        ->and($tasks->first())->toBeArray()
        ->and($tasks->first())->toHaveKey('task_id');
});

test('it parses from a string', function () {
    $content = "ERMHDR\t12.0\n%T\tTASK\n%F\ttask_id\ttask_code\ttask_name\n%R\t1\tA100\tTest Task\n";
    $parser = new XerParser;
    $data = $parser->parse($content);

    expect($data->getTable('TASK'))->toHaveCount(1)
        ->and($data->getTable('TASK')->first()['task_code'])->toBe('A100');
});

test('it throws XerParseException for missing file', function () {
    $parser = new XerParser;
    $parser->parseFile('/nonexistent/file.xer');
})->throws(XerParseException::class);

test('it handles empty content', function () {
    $parser = new XerParser;
    $data = $parser->parse('');

    expect($data->tables)->toBeEmpty();
});

test('it handles multiple tables', function () {
    $content = "ERMHDR\t12.0\n%T\tTASK\n%F\ttask_id\n%R\t1\n%T\tPROJECT\n%F\tproj_id\n%R\t2\n";
    $parser = new XerParser;
    $data = $parser->parse($content);

    expect($data->getTable('TASK'))->toHaveCount(1)
        ->and($data->getTable('PROJECT'))->toHaveCount(1);
});

test('it handles trailing tabs and value padding', function () {
    $content = "ERMHDR\t12.0\n%T\tTASK\n%F\ttask_id\ttask_code\ttask_name\n%R\t1\tA100\n";
    $parser = new XerParser;
    $data = $parser->parse($content);

    $row = $data->getTable('TASK')->first();
    expect($row['task_name'])->toBe('');
});
