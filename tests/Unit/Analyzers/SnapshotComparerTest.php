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
