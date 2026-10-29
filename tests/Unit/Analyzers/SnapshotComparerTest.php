<?php

use TeckFuture\P6Analyzer\Analyzers\SnapshotComparer;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\IO\XerParser;

test('same file has zero changes', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new SnapshotComparer)->compare($data, $data);

    expect($result['total_changes'])->toBe(0)
        ->and($result['added_activities'])->toBeEmpty()
        ->and($result['deleted_activities'])->toBeEmpty()
        ->and($result['modified_activities'])->toBeEmpty();
});

test('it detects added activities', function () {
    $baseline = new ParsedXerData(header: '', tables: collect([
        'TASK' => collect([
            ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'Existing'],
        ]),
    ]));

    $current = new ParsedXerData(header: '', tables: collect([
        'TASK' => collect([
            ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'Existing'],
            ['task_id' => '2', 'task_code' => 'A200', 'task_name' => 'New Task'],
        ]),
    ]));

    $result = (new SnapshotComparer)->compare($current, $baseline);

    expect($result['added_activities'])->toHaveCount(1)
        ->and($result['added_activities'][0])->toBe('A200')
        ->and($result['deleted_activities'])->toBeEmpty()
        ->and($result['total_changes'])->toBe(1);
});

test('it detects deleted activities', function () {
    $baseline = new ParsedXerData(header: '', tables: collect([
        'TASK' => collect([
            ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'Still Here'],
            ['task_id' => '2', 'task_code' => 'A200', 'task_name' => 'Will Be Deleted'],
        ]),
    ]));

    $current = new ParsedXerData(header: '', tables: collect([
        'TASK' => collect([
            ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'Still Here'],
        ]),
    ]));

    $result = (new SnapshotComparer)->compare($current, $baseline);

    expect($result['deleted_activities'])->toHaveCount(1)
        ->and($result['deleted_activities'][0])->toBe('A200')
        ->and($result['added_activities'])->toBeEmpty()
        ->and($result['total_changes'])->toBe(1);
});

test('it detects status changes', function () {
    $baseline = new ParsedXerData(header: '', tables: collect([
        'TASK' => collect([
            ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'Task', 'status_code' => 'TK_NotStart'],
        ]),
    ]));

    $current = new ParsedXerData(header: '', tables: collect([
        'TASK' => collect([
            ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'Task', 'status_code' => 'TK_Active'],
        ]),
    ]));

    $result = (new SnapshotComparer)->compare($current, $baseline);

    expect($result['modified_activities'])->toHaveKey('A100')
        ->and($result['modified_activities']['A100'])->toHaveKey('status')
        ->and($result['modified_activities']['A100']['status']['from'])->toBe('Not Started')
        ->and($result['modified_activities']['A100']['status']['to'])->toBe('In Progress')
        ->and($result['total_changes'])->toBe(1);
});

test('it detects start date changes', function () {
    $baseline = new ParsedXerData(header: '', tables: collect([
        'TASK' => collect([
            ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'Task', 'target_start_date' => '2024-01-01'],
        ]),
    ]));

    $current = new ParsedXerData(header: '', tables: collect([
        'TASK' => collect([
            ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'Task', 'target_start_date' => '2024-02-01'],
        ]),
    ]));

    $result = (new SnapshotComparer)->compare($current, $baseline);

    expect($result['modified_activities'])->toHaveKey('A100')
        ->and($result['modified_activities']['A100'])->toHaveKey('start_date');
});

test('handles empty datasets', function () {
    $empty = new ParsedXerData(header: '', tables: collect());
    $result = (new SnapshotComparer)->compare($empty, $empty);

    expect($result['total_changes'])->toBe(0);
});

// ---------------------------------------------------------------
// Activities without a task_code have nothing to be matched on
// ---------------------------------------------------------------

function makeSnapshotData(array $tasks): ParsedXerData
{
    return new ParsedXerData(header: '', tables: collect(['TASK' => collect($tasks)]));
}

test('activities without a task_code are skipped, not collapsed onto one blank key', function () {
    $baseline = makeSnapshotData([
        ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'Kept', 'status_code' => 'TK_NotStart'],
        ['task_id' => '2', 'task_code' => '', 'task_name' => 'Blank one', 'status_code' => 'TK_NotStart'],
        ['task_id' => '3', 'task_code' => '', 'task_name' => 'Blank two', 'status_code' => 'TK_Active'],
    ]);
    $current = makeSnapshotData([
        ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'Kept', 'status_code' => 'TK_Active'],
        ['task_id' => '2', 'task_code' => '', 'task_name' => 'Blank one', 'status_code' => 'TK_Complete'],
        ['task_id' => '4', 'task_code' => '', 'task_name' => 'Blank three', 'status_code' => 'TK_NotStart'],
    ]);

    $result = (new SnapshotComparer)->compare($current, $baseline);

    expect(array_keys($result['modified_activities']))->toBe(['A100'])
        ->and($result['added_activities'])->toBeEmpty()
        ->and($result['deleted_activities'])->toBeEmpty()
        ->and($result['total_changes'])->toBe(1);
});

test('an activity without a task_code is not reported as added', function () {
    $baseline = makeSnapshotData([
        ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'Existing'],
    ]);
    $current = makeSnapshotData([
        ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'Existing'],
        ['task_id' => '2', 'task_code' => '', 'task_name' => 'No code'],
        ['task_id' => '3', 'task_name' => 'No task_code column at all'],
    ]);

    $result = (new SnapshotComparer)->compare($current, $baseline);

    expect($result['added_activities'])->toBeEmpty()
        ->and($result['total_changes'])->toBe(0);
});

test('an activity without a task_code is not reported as deleted', function () {
    $baseline = makeSnapshotData([
        ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'Existing'],
        ['task_id' => '2', 'task_code' => '', 'task_name' => 'No code'],
    ]);
    $current = makeSnapshotData([
        ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'Existing'],
    ]);

    $result = (new SnapshotComparer)->compare($current, $baseline);

    expect($result['deleted_activities'])->toBeEmpty()
        ->and($result['total_changes'])->toBe(0);
});
