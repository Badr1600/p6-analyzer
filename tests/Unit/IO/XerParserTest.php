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

test('it throws XerParseException when the path is a directory, without raising a PHP warning', function () {
    $raised = [];
    set_error_handler(function (int $level, string $message) use (&$raised): bool {
        $raised[] = $message;

        return true;
    });

    try {
        expect(fn () => (new XerParser)->parseFile(__DIR__))->toThrow(XerParseException::class, __DIR__);
    } finally {
        restore_error_handler();
    }

    expect($raised)->toBe([]);
});

test('it throws XerParseException for a file that cannot be read', function () {
    $path = tempnam(sys_get_temp_dir(), 'xer_unreadable_');
    file_put_contents($path, "ERMHDR\t12.0\n%T\tTASK\n%F\ttask_id\n%R\t1\n");
    chmod($path, 0000);

    try {
        expect(fn () => (new XerParser)->parseFile($path))
            ->toThrow(XerParseException::class, "Failed to read XER file at path: {$path}");
    } finally {
        chmod($path, 0600);
        unlink($path);
    }
})->skipOnWindows()->skip(
    fn () => function_exists('posix_geteuid') && posix_geteuid() === 0,
    'root can read any file',
);

test('it rejects empty content', function () {
    $parser = new XerParser;
    $parser->parse('');
})->throws(XerParseException::class, 'not a valid XER file');

test('it rejects content that is not an XER file', function (string $content) {
    (new XerParser)->parse($content);
})->with([
    'whitespace only' => ["  \n\t\r\n"],
    'plain text' => ['This is not an XER file.'],
    'binary data' => ["\x00\x01\x02\xFF\xFE\x89PNG\r\n"],
    'a lone end marker' => ["%E\n"],
    'rows without a table' => ["%R\t1\t2\n"],
])->throws(XerParseException::class, 'not a valid XER file');

test('it accepts content that has tables but no ERMHDR header', function () {
    $data = (new XerParser)->parse("%T\tTASK\n%F\ttask_id\n%R\t1\n");

    expect($data->header)->toBe('')
        ->and($data->getTable('TASK'))->toHaveCount(1);
});

test('it accepts a header without any table', function () {
    $data = (new XerParser)->parse("ERMHDR\t12.0\n");

    expect($data->header)->toBe("ERMHDR\t12.0")
        ->and($data->tables)->toBeEmpty();
});

test('it strips a leading UTF-8 byte order mark', function () {
    $content = "\xEF\xBB\xBFERMHDR\t12.0\n%T\tRSRC\n%F\trsrc_id\trsrc_name\n%R\t1\tCafé\n";
    $data = (new XerParser)->parse($content);

    expect($data->header)->toBe("ERMHDR\t12.0")
        ->and($data->getTable('RSRC')->first())->toBe(['rsrc_id' => '1', 'rsrc_name' => 'Café']);
});

test('it converts Windows-1252 content to UTF-8', function () {
    // 0xE9 is é, 0xA3 is £ and 0x80 is € (a byte Latin-1 does not map)
    $content = "ERMHDR\t12.0\n%T\tRSRC\n%F\trsrc_id\trsrc_name\n%R\t1\tCaf\xE9\n%R\t2\t\xA3100\n%R\t3\t\x80 50\n";
    $names = (new XerParser)->parse($content)->getTable('RSRC')->pluck('rsrc_name')->all();

    expect($names)->toBe(['Café', '£100', '€ 50']);
});

test('it leaves valid UTF-8 content unchanged', function () {
    $content = "ERMHDR\t12.0\n%T\tRSRC\n%F\trsrc_id\trsrc_name\n%R\t1\tCafé\n%R\t2\t£100 €50 ¥\n";
    $names = (new XerParser)->parse($content)->getTable('RSRC')->pluck('rsrc_name')->all();

    expect($names)->toBe(['Café', '£100 €50 ¥']);
});

test('it converts the Windows-1252 currency symbols of the fixture to UTF-8', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $symbols = $data->getTable('CURRTYPE')->pluck('curr_symbol', 'curr_short_name');

    expect($symbols['U.K.'])->toBe('£')
        ->and($symbols['EUR'])->toBe('€')
        ->and($symbols['JPY'])->toBe('¥')
        ->and(mb_check_encoding(serialize($data->tables->toArray()), 'UTF-8'))->toBeTrue();
});

test('it parses the fixture to the same tables and row counts after the encoding conversion', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());

    expect($data->tables->map(fn ($rows) => $rows->count())->all())->toBe([
        'CURRTYPE' => 17,
        'NONWORK' => 7,
        'TASKPRED' => 555,
        'TASK' => 435,
        'PROJECT' => 1,
        'FINTMPL' => 1,
        'PROJWBS' => 19,
        'TASKRSRC' => 1677,
        'TASKMEMO' => 1,
        'TASKACTV' => 436,
        'PROJPCAT' => 4,
        'ACTVTYPE' => 3,
        'ACTVCODE' => 3,
        'MEMOTYPE' => 1,
        'SCHEDOPTIONS' => 1,
        'OBS' => 1,
        'UDFVALUE' => 87,
        'RSRC' => 29,
        'RSRCRATE' => 29,
        'RSRCCURVDATA' => 0,
        'ROLES' => 13,
        'ROLERATE' => 12,
        'PCATTYPE' => 4,
        'PCATVAL' => 4,
        'UDFTYPE' => 3,
        'CALENDAR' => 1,
    ]);
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
