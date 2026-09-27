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

test('it keeps continuation lines of the multi-line CALENDAR clndr_data value', function () {
    $parser = new XerParser;
    $data = $parser->parseFile($this->fixturePath());

    $calendar = $data->getTable('CALENDAR')->first();
    $calendarData = $calendar['clndr_data'];

    expect($data->getTable('CALENDAR'))->toHaveCount(1)
        ->and($calendar['clndr_id'])->toBe('597')
        ->and($calendarData)->toStartWith('"(0||CalendarData()(')
        ->and($calendarData)->toContain('DaysOfWeek')
        ->and($calendarData)->toContain('Exceptions')
        ->and(rtrim($calendarData, '"'))->toEndWith('(0||72(d|47116)())))))')
        ->and(substr_count($calendarData, "\n"))->toBe(99)
        ->and($calendarData)->not->toContain("\r");
});

test('it round-trips a two-line row from a string', function () {
    $content = "ERMHDR\t12.0\n"
        ."%T\tCALENDAR\n"
        ."%F\tclndr_id\tclndr_name\tclndr_data\n"
        ."%R\t1\tStandard\t(0||CalendarData()(\n"
        ."  (0||DaysOfWeek()()))\n"
        ."%R\t2\tSingle\t(0||CalendarData()())\n"
        ."%E\n";
    $parser = new XerParser;
    $rows = $parser->parse($content)->getTable('CALENDAR');

    expect($rows)->toHaveCount(2)
        ->and($rows->first())->toBe([
            'clndr_id' => '1',
            'clndr_name' => 'Standard',
            'clndr_data' => "(0||CalendarData()(\n  (0||DaysOfWeek()()))",
        ])
        ->and($rows->last()['clndr_data'])->toBe('(0||CalendarData()())');
});

test('it ignores stray lines before a table has any row', function () {
    $content = "ERMHDR\t12.0\nstray before any table\n%T\tTASK\nstray before fields\n%F\ttask_id\ttask_name\nstray before rows\n%R\t1\tFirst\n";
    $parser = new XerParser;
    $rows = $parser->parse($content)->getTable('TASK');

    expect($rows)->toHaveCount(1)
        ->and($rows->first())->toBe(['task_id' => '1', 'task_name' => 'First']);
});

test('it leaves single-line tables unchanged', function () {
    $fixture = file_get_contents($this->fixturePath());
    $physicalLines = explode("\n", str_replace("\r\n", "\n", $fixture));
    $singleLineOnly = implode("\n", array_filter(
        $physicalLines,
        fn (string $line): bool => (bool) preg_match('/^(ERMHDR|%T|%F|%R|%E)/', $line),
    ));

    $parser = new XerParser;
    $withContinuations = $parser->parse($fixture);
    $withoutContinuations = $parser->parse($singleLineOnly);

    $firstAssignment = $withContinuations->getTable('TASKRSRC')->first();

    expect($withContinuations->getTable('TASK'))->toHaveCount(435)
        ->and($withContinuations->getTable('TASKRSRC'))->toHaveCount(1677)
        ->and($firstAssignment['taskrsrc_id'])->toBe('488785')
        ->and($firstAssignment['role_id'])->toBe('1783')
        ->and($firstAssignment['rsrc_id'])->toBe('1317')
        ->and($firstAssignment['remain_qty'])->toBe('3.0')
        ->and($firstAssignment['target_start_date'])->toBe('2027-04-28 16:59')
        ->and($firstAssignment['taskrsrc_sum_id'])->toBe('');

    foreach ($withoutContinuations->tables->keys() as $tableName) {
        if ($tableName === 'CALENDAR') {
            continue;
        }

        expect($withContinuations->getTable($tableName)->toArray())
            ->toBe($withoutContinuations->getTable($tableName)->toArray());
    }
});
