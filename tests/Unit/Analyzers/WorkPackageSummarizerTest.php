<?php

use TeckFuture\P6Analyzer\Analyzers\WorkPackageSummarizer;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\IO\XerParser;

covers(WorkPackageSummarizer::class);

function makeWpData(array $tasks = [], array $wbs = []): ParsedXerData
{
    return new ParsedXerData(header: '', tables: collect([
        'TASK' => collect($tasks),
        'PROJWBS' => collect($wbs),
    ]));
}

function makeTask(string $id, string $wbsId, string $status = 'TK_Active', float $duration = 40.0, float $cost = 0.0): array
{
    return [
        'task_id' => $id,
        'status_code' => $status,
        'wbs_id' => $wbsId,
        'target_drtn_hr_cnt' => (string) $duration,
        'target_cost' => (string) $cost,
    ];
}

// ---------------------------------------------------------------
// Empty input
// ---------------------------------------------------------------

test('empty data returns empty array', function () {
    expect((new WorkPackageSummarizer)->summarize(makeWpData()))->toBe([]);
});

// ---------------------------------------------------------------
// WBS name resolution
// ---------------------------------------------------------------

test('uses WBS name from PROJWBS table', function () {
    $data = makeWpData(
        tasks: [makeTask('1', 'W1')],
        wbs: [['wbs_id' => 'W1', 'wbs_name' => 'Phase 1']],
    );

    $result = (new WorkPackageSummarizer)->summarize($data);
    expect($result[0]['name'])->toBe('Phase 1');
});

test('falls back to wbs_id when wbs_name missing in table', function () {
    $data = makeWpData(
        tasks: [makeTask('1', 'W1')],
        wbs: [['wbs_id' => 'W1']], // no wbs_name
    );

    $result = (new WorkPackageSummarizer)->summarize($data);
    expect($result[0]['name'])->toBe('W1');
});

test('falls back to wbs_id when wbs not in PROJWBS table', function () {
    $data = makeWpData(tasks: [makeTask('1', 'ORPHAN')]);

    $result = (new WorkPackageSummarizer)->summarize($data);
    expect($result[0]['name'])->toBe('ORPHAN');
});

test('task with no wbs_id goes to unassigned', function () {
    $data = makeWpData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Active', 'target_drtn_hr_cnt' => '40'],
    ]);

    $result = (new WorkPackageSummarizer)->summarize($data);
    expect($result[0]['name'])->toBe('unassigned');
});

// ---------------------------------------------------------------
// Counts and aggregations
// ---------------------------------------------------------------

test('activity_count increments per activity in same wbs', function () {
    $data = makeWpData(tasks: [
        makeTask('1', 'W1'), makeTask('2', 'W1'), makeTask('3', 'W1'),
    ]);

    expect((new WorkPackageSummarizer)->summarize($data)[0]['activity_count'])->toBe(3);
});

test('total_duration_hr aggregates across all activities', function () {
    $data = makeWpData(tasks: [
        makeTask('1', 'W1', duration: 40.0),
        makeTask('2', 'W1', duration: 80.0),
    ]);

    expect((new WorkPackageSummarizer)->summarize($data)[0]['total_duration_hr'])->toBe(120.0);
});

test('total_cost aggregates across all activities', function () {
    $data = makeWpData(tasks: [
        makeTask('1', 'W1', cost: 1000.0),
        makeTask('2', 'W1', cost: 500.0),
    ]);

    expect((new WorkPackageSummarizer)->summarize($data)[0]['total_cost'])->toBe(1500.0);
});

test('completed_activities only counts TK_Complete activities', function () {
    $data = makeWpData(tasks: [
        makeTask('1', 'W1', 'TK_Complete'),
        makeTask('2', 'W1', 'TK_Active'),
        makeTask('3', 'W1', 'TK_Complete'),
    ]);

    $result = (new WorkPackageSummarizer)->summarize($data)[0];
    expect($result['completed_activities'])->toBe(2)
        ->and($result['activity_count'])->toBe(3);
});

// ---------------------------------------------------------------
// percent_complete_by_count
// ---------------------------------------------------------------

test('percent_complete_by_count is 100 when all activities completed', function () {
    $data = makeWpData(tasks: [
        makeTask('1', 'W1', 'TK_Complete'),
        makeTask('2', 'W1', 'TK_Complete'),
    ]);

    expect((new WorkPackageSummarizer)->summarize($data)[0]['percent_complete_by_count'])->toBe(100.0);
});

test('percent_complete_by_count is 0 when no activities completed', function () {
    $data = makeWpData(tasks: [makeTask('1', 'W1', 'TK_Active')]);

    expect((new WorkPackageSummarizer)->summarize($data)[0]['percent_complete_by_count'])->toBe(0.0);
});

test('percent_complete_by_count is 50 for half-complete package', function () {
    $data = makeWpData(tasks: [
        makeTask('1', 'W1', 'TK_Complete'),
        makeTask('2', 'W1', 'TK_Active'),
    ]);

    expect((new WorkPackageSummarizer)->summarize($data)[0]['percent_complete_by_count'])->toBe(50.0);
});

test('percent_complete_by_count rounds to 2 decimal places', function () {
    // 1 of 3 = 33.33...
    $data = makeWpData(tasks: [
        makeTask('1', 'W1', 'TK_Complete'),
        makeTask('2', 'W1', 'TK_Active'),
        makeTask('3', 'W1', 'TK_Active'),
    ]);

    expect((new WorkPackageSummarizer)->summarize($data)[0]['percent_complete_by_count'])->toBe(33.33);
});

// ---------------------------------------------------------------
// Multiple packages
// ---------------------------------------------------------------

test('separate packages are created per WBS node', function () {
    $data = makeWpData(tasks: [
        makeTask('1', 'W1'), makeTask('2', 'W2'),
    ]);

    expect((new WorkPackageSummarizer)->summarize($data))->toHaveCount(2);
});

test('runs against real XER fixture without error', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new WorkPackageSummarizer)->summarize($data);

    expect($result)->toBeArray();
    if (count($result) > 0) {
        expect($result[0])->toHaveKeys(['name', 'activity_count', 'total_duration_hr', 'total_cost', 'completed_activities', 'percent_complete_by_count']);
    }
});

// ---------------------------------------------------------------
// percent_complete_by_count rounding
// ---------------------------------------------------------------

test('percent_complete_by_count is 14.29 for 1 of 7 completed', function () {
    // 1/7 * 100 = 14.285... → 14.29 at 2dp (not 14.3 at 1dp)
    $tasks = [];
    for ($i = 1; $i <= 6; $i++) {
        $tasks[] = makeTask((string) $i, 'W1', 'TK_Active');
    }
    $tasks[] = makeTask('7', 'W1', 'TK_Complete');

    $result = (new WorkPackageSummarizer)->summarize(makeWpData(tasks: $tasks));

    expect($result[0]['percent_complete_by_count'])->toBe(14.29);
});

test('percent_complete_by_count is 71.43 for 5 of 7 completed', function () {
    // 5/7 * 100 = 71.428... → 71.43
    $tasks = [];
    for ($i = 1; $i <= 5; $i++) {
        $tasks[] = makeTask((string) $i, 'W1', 'TK_Complete');
    }
    for ($i = 6; $i <= 7; $i++) {
        $tasks[] = makeTask((string) $i, 'W1', 'TK_Active');
    }

    $result = (new WorkPackageSummarizer)->summarize(makeWpData(tasks: $tasks));

    expect($result[0]['percent_complete_by_count'])->toBe(71.43);
});

// ---------------------------------------------------------------
// A missing duration or cost counts as zero
// ---------------------------------------------------------------

test('activity with null duration contributes 0 to total_duration_hr', function () {
    $data = makeWpData(tasks: [[
        'task_id' => '1',
        'status_code' => 'TK_Active',
        'wbs_id' => 'W1',
        // No target_drtn_hr_cnt
    ]]);

    $result = (new WorkPackageSummarizer)->summarize($data);

    expect($result[0]['total_duration_hr'])->toBe(0.0);
});

test('activity with null cost contributes 0 to total_cost', function () {
    $data = makeWpData(tasks: [[
        'task_id' => '1',
        'status_code' => 'TK_Active',
        'wbs_id' => 'W1',
        // No target_cost
    ]]);

    $result = (new WorkPackageSummarizer)->summarize($data);

    expect($result[0]['total_cost'])->toBe(0.0);
});
