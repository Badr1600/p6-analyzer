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

// ---------------------------------------------------------------
// Windows-1252 output
// ---------------------------------------------------------------

test('it writes UTF-8 values as Windows-1252 bytes', function () {
    $data = new ParsedXerData(
        header: "ERMHDR\t8.0\tJosé",
        tables: collect([
            'RSRC' => collect([['rsrc_id' => '1', 'rsrc_name' => 'Café £5 €']]),
        ])
    );

    $xer = (new XerExporter)->export($data);

    expect($xer)->toContain("ERMHDR\t8.0\tJos\xE9\n")
        ->and($xer)->toContain("%R\t1\tCaf\xE9 \xA35 \x80\n")
        ->and(mb_check_encoding($xer, 'UTF-8'))->toBeFalse();
});

test('characters that Windows-1252 cannot represent are written as a question mark', function () {
    $data = new ParsedXerData(
        header: '',
        tables: collect([
            'RSRC' => collect([['rsrc_name' => 'Ωmega 中文']]),
        ])
    );

    expect((new XerExporter)->export($data))->toContain("%R\t?mega ??\n");
});

test('every defined Windows-1252 byte survives a parse and an export unchanged', function () {
    // 0x81, 0x8D, 0x8F, 0x90 and 0x9D have no meaning in Windows-1252
    $bytes = array_diff(range(0x80, 0xFF), [0x81, 0x8D, 0x8F, 0x90, 0x9D]);
    $xer = "ERMHDR\t8.0\n%T\tRSRC\n%F\trsrc_name\n"
        .implode('', array_map(fn (int $byte): string => "%R\t".chr($byte)."\n", $bytes))
        ."%E\n";

    expect((new XerExporter)->export((new XerParser)->parse($xer)))->toBe($xer);
});

test('exporting the parsed fixture reproduces its bytes, apart from line endings and the empty RSRCCURVDATA table', function () {
    // The exporter writes LF line endings and leaves out tables without rows
    $expected = preg_replace(
        "/^%T\tRSRCCURVDATA\n%F[^\n]*\n/m",
        '',
        str_replace("\r\n", "\n", file_get_contents($this->fixturePath())),
    );

    $xer = (new XerExporter)->export((new XerParser)->parseFile($this->fixturePath()));

    expect($xer)->toBe($expected)
        ->and($xer)->toContain("\t\xA3\t")
        ->and(mb_check_encoding($xer, 'UTF-8'))->toBeFalse();
});

// ---------------------------------------------------------------
// Writing files
// ---------------------------------------------------------------

/**
 * Run the callback with the path of a new, empty directory, and remove the directory afterwards.
 */
function inTemporaryDirectory(Closure $callback): void
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'xer_export_'.bin2hex(random_bytes(6));
    mkdir($directory);

    try {
        $callback($directory);
    } finally {
        foreach (array_diff(scandir($directory), ['.', '..']) as $entry) {
            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? rmdir($path) : unlink($path);
        }

        rmdir($directory);
    }
}

/**
 * Export to a file while recording every PHP error raised meanwhile.
 *
 * @return array{0: bool, 1: array<int, string>} Whether the file was written, and the recorded error messages.
 */
function exportToFileRecordingErrors(ParsedXerData $data, string $path): array
{
    $raised = [];
    set_error_handler(function (int $level, string $message) use (&$raised): bool {
        $raised[] = $message;

        return true;
    });

    try {
        $written = (new XerExporter)->exportToFile($data, $path);
    } finally {
        restore_error_handler();
    }

    return [$written, $raised];
}

test('exportToFile replaces an existing file instead of rewriting it in place', function () {
    inTemporaryDirectory(function (string $directory) {
        $path = $directory.'/schedule.xer';
        file_put_contents($path, 'old content');
        clearstatcache();
        $inodeBefore = fileinode($path);

        $written = (new XerExporter)->exportToFile(new ParsedXerData(header: "ERMHDR\t8.0", tables: collect()), $path);
        clearstatcache();

        expect($written)->toBeTrue()
            ->and(file_get_contents($path))->toBe("ERMHDR\t8.0\n%E\n")
            ->and(fileinode($path))->not->toBe($inodeBefore);
    });
})->skipOnWindows();

test('exportToFile leaves no temporary file behind', function () {
    inTemporaryDirectory(function (string $directory) {
        $data = new ParsedXerData(header: "ERMHDR\t8.0", tables: collect());

        $first = (new XerExporter)->exportToFile($data, $directory.'/schedule.xer');
        $second = (new XerExporter)->exportToFile($data, $directory.'/schedule.xer');

        expect($first)->toBeTrue()
            ->and($second)->toBeTrue()
            ->and(scandir($directory))->toBe(['.', '..', 'schedule.xer']);
    });
});

test('exportToFile keeps the permissions of the file it replaces', function () {
    inTemporaryDirectory(function (string $directory) {
        $path = $directory.'/schedule.xer';
        file_put_contents($path, 'old content');
        chmod($path, 0640);

        (new XerExporter)->exportToFile(new ParsedXerData(header: "ERMHDR\t8.0", tables: collect()), $path);
        clearstatcache();

        expect(fileperms($path) & 0777)->toBe(0640);
    });
})->skipOnWindows();

test('exportToFile writes through a symbolic link instead of replacing it', function () {
    inTemporaryDirectory(function (string $directory) {
        file_put_contents($directory.'/real.xer', 'old content');
        symlink($directory.'/real.xer', $directory.'/link.xer');

        $written = (new XerExporter)->exportToFile(new ParsedXerData(header: "ERMHDR\t8.0", tables: collect()), $directory.'/link.xer');

        expect($written)->toBeTrue()
            ->and(is_link($directory.'/link.xer'))->toBeTrue()
            ->and(file_get_contents($directory.'/real.xer'))->toBe("ERMHDR\t8.0\n%E\n");
    });
})->skipOnWindows();

test('exportToFile returns false without raising a PHP warning when the directory does not exist', function () {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'xer_missing_'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR.'schedule.xer';

    [$written, $raised] = exportToFileRecordingErrors(new ParsedXerData(header: "ERMHDR\t8.0", tables: collect()), $path);

    expect($written)->toBeFalse()
        ->and($raised)->toBe([])
        ->and(file_exists(dirname($path)))->toBeFalse();
});

test('exportToFile returns false without raising a PHP warning for an empty path', function () {
    [$written, $raised] = exportToFileRecordingErrors(new ParsedXerData(header: "ERMHDR\t8.0", tables: collect()), '');

    expect($written)->toBeFalse()
        ->and($raised)->toBe([]);
});

test('exportToFile returns false and leaves nothing behind when the target is a directory', function () {
    inTemporaryDirectory(function (string $directory) {
        mkdir($directory.'/schedule.xer');

        [$written, $raised] = exportToFileRecordingErrors(new ParsedXerData(header: "ERMHDR\t8.0", tables: collect()), $directory.'/schedule.xer');

        expect($written)->toBeFalse()
            ->and($raised)->toBe([])
            ->and(scandir($directory))->toBe(['.', '..', 'schedule.xer'])
            ->and(is_dir($directory.'/schedule.xer'))->toBeTrue();
    });
});

test('exportToFile returns false and does not replace a target that is not a regular file', function () {
    inTemporaryDirectory(function (string $directory) {
        posix_mkfifo($directory.'/schedule.xer', 0600);

        [$written, $raised] = exportToFileRecordingErrors(new ParsedXerData(header: "ERMHDR\t8.0", tables: collect()), $directory.'/schedule.xer');
        clearstatcache();

        expect($written)->toBeFalse()
            ->and($raised)->toBe([])
            ->and(filetype($directory.'/schedule.xer'))->toBe('fifo')
            ->and(scandir($directory))->toBe(['.', '..', 'schedule.xer']);
    });
})->skipOnWindows()->skip(
    fn () => ! function_exists('posix_mkfifo'),
    'needs the posix extension',
);

test('exportToFile returns false and keeps the content when the existing file is read-only', function () {
    inTemporaryDirectory(function (string $directory) {
        $path = $directory.'/schedule.xer';
        file_put_contents($path, 'old content');
        chmod($path, 0444);

        [$written, $raised] = exportToFileRecordingErrors(new ParsedXerData(header: "ERMHDR\t8.0", tables: collect()), $path);

        expect($written)->toBeFalse()
            ->and($raised)->toBe([])
            ->and(file_get_contents($path))->toBe('old content')
            ->and(scandir($directory))->toBe(['.', '..', 'schedule.xer']);
    });
})->skipOnWindows()->skip(
    fn () => function_exists('posix_geteuid') && posix_geteuid() === 0,
    'root can write to any file',
);
