<?php

use TeckFuture\P6Analyzer\Analyzers\DCMA14PointChecker;
use TeckFuture\P6Analyzer\Analyzers\ScheduleHealthChecker;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\IO\XerParser;

covers(ScheduleHealthChecker::class);

function makeHealthData(array $tasks = [], array $relationships = [], array $taskrsrc = []): ParsedXerData
{
    return new ParsedXerData(header: '', tables: collect([
        'TASK' => collect($tasks),
        'TASKPRED' => collect($relationships),
        'TASKRSRC' => collect($taskrsrc),
    ]));
}

// ---------------------------------------------------------------
// Empty input
// ---------------------------------------------------------------

test('empty data returns all zeros', function () {
    $result = (new ScheduleHealthChecker)->check(makeHealthData());

    expect($result['total_activities'])->toBe(0)
        ->and($result['missing_resources'])->toBe(0)
        ->and($result['missing_logic'])->toBe(0)
        ->and($result['lags_count'])->toBe(0)
        ->and($result['leads_count'])->toBe(0)
        ->and($result['actual_start_after_data_date'])->toBe(0);
});

test('the result names its counts and drill-down lists', function () {
    $result = (new ScheduleHealthChecker)->check(makeHealthData());

    expect(array_keys($result))->toBe([
        'total_activities', 'missing_resources', 'missing_logic', 'actual_start_after_data_date',
        'lags_count', 'leads_count', 'details_limit',
        'missing_resources_activities', 'missing_logic_activities', 'actual_start_after_data_date_activities',
    ]);
});

// ---------------------------------------------------------------
// total_activities count
// ---------------------------------------------------------------

test('total_activities reflects all TASK rows', function () {
    $data = makeHealthData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Active'],
        ['task_id' => '2', 'status_code' => 'TK_Complete'],
    ]);

    expect((new ScheduleHealthChecker)->check($data)['total_activities'])->toBe(2);
});

// ---------------------------------------------------------------
// missing_resources
// ---------------------------------------------------------------

test('activity without resource assignment counted as missing', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active']],
        taskrsrc: [] // no assignments
    );

    expect((new ScheduleHealthChecker)->check($data)['missing_resources'])->toBe(1);
});

test('activity with resource assignment is not counted as missing', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active']],
        taskrsrc: [['task_id' => '1', 'rsrc_id' => 'R1']]
    );

    expect((new ScheduleHealthChecker)->check($data)['missing_resources'])->toBe(0);
});

// ---------------------------------------------------------------
// missing_logic
// ---------------------------------------------------------------

test('activity with no predecessor and no successor counted as missing logic', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'task_type' => 'TT_Task']],
    );

    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(1);
});

test('start milestone with only a successor is not missing logic', function () {
    // Nothing leads to milestone 1, but task 2 follows it
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'task_type' => 'TT_Mile']],
        relationships: [['task_pred_id' => '99', 'task_id' => '2', 'pred_task_id' => '1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0']],
    );

    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(0);
});

test('start milestone without a successor counted as missing logic', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'task_type' => 'TT_Mile']],
        relationships: [['task_pred_id' => '99', 'task_id' => '1', 'pred_task_id' => '2', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0']],
    );

    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(1);
});

test('finish milestone with only a predecessor is not missing logic', function () {
    // Task 2 leads to milestone 1, but nothing follows it
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'task_type' => 'TT_FinMile']],
        relationships: [['task_pred_id' => '99', 'task_id' => '1', 'pred_task_id' => '2', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0']],
    );

    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(0);
});

test('finish milestone without a predecessor counted as missing logic', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'task_type' => 'TT_FinMile']],
        relationships: [['task_pred_id' => '99', 'task_id' => '2', 'pred_task_id' => '1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0']],
    );

    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(1);
});

test('activity with a predecessor but no successor counted as missing logic', function () {
    // Task 2 follows task 1, but nothing follows task 2
    $data = makeHealthData(
        tasks: [['task_id' => '2', 'status_code' => 'TK_Active', 'task_type' => 'TT_Task']],
        relationships: [['task_pred_id' => '99', 'task_id' => '2', 'pred_task_id' => '1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0']],
    );

    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(1);
});

test('activity with a successor but no predecessor counted as missing logic', function () {
    // Task 1 leads to task 2, but nothing leads to task 1
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'task_type' => 'TT_Task']],
        relationships: [['task_pred_id' => '99', 'task_id' => '2', 'pred_task_id' => '1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0']],
    );

    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(1);
});

test('activity with both a predecessor and a successor is not missing logic', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '2', 'status_code' => 'TK_Active', 'task_type' => 'TT_Task']],
        relationships: [
            ['task_pred_id' => '98', 'task_id' => '2', 'pred_task_id' => '1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0'],
            ['task_pred_id' => '99', 'task_id' => '3', 'pred_task_id' => '2', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0'],
        ],
    );

    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(0);
});

test('completed activity is excluded from missing logic', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Complete', 'task_type' => 'TT_Task']],
    );

    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(0);
});

test('WBS summary activity is excluded from missing logic', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'task_type' => 'TT_WBS']],
    );

    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(0);
});

// ---------------------------------------------------------------
// Lags and leads
// ---------------------------------------------------------------

test('positive lag increments lags_count', function () {
    $data = makeHealthData(
        relationships: [['task_pred_id' => '1', 'task_id' => 'T2', 'pred_task_id' => 'T1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '8']],
    );

    expect((new ScheduleHealthChecker)->check($data)['lags_count'])->toBe(1)
        ->and((new ScheduleHealthChecker)->check($data)['leads_count'])->toBe(0);
});

test('negative lag increments leads_count', function () {
    $data = makeHealthData(
        relationships: [['task_pred_id' => '1', 'task_id' => 'T2', 'pred_task_id' => 'T1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '-8']],
    );

    expect((new ScheduleHealthChecker)->check($data)['leads_count'])->toBe(1)
        ->and((new ScheduleHealthChecker)->check($data)['lags_count'])->toBe(0);
});

test('zero lag increments neither lags nor leads', function () {
    $data = makeHealthData(
        relationships: [['task_pred_id' => '1', 'task_id' => 'T2', 'pred_task_id' => 'T1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0']],
    );

    expect((new ScheduleHealthChecker)->check($data)['lags_count'])->toBe(0)
        ->and((new ScheduleHealthChecker)->check($data)['leads_count'])->toBe(0);
});

// ---------------------------------------------------------------
// actual_start_after_data_date
// ---------------------------------------------------------------

test('activity with actual start after data date is counted', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'act_start_date' => '2024-03-01 00:00:00']],
    );

    $result = (new ScheduleHealthChecker)->check($data, '2024-01-01');

    expect($result['actual_start_after_data_date'])->toBe(1);
});

test('activity with actual start before data date is not counted', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'act_start_date' => '2023-06-01 00:00:00']],
    );

    $result = (new ScheduleHealthChecker)->check($data, '2024-01-01');

    expect($result['actual_start_after_data_date'])->toBe(0);
});

test('no data date means actual_start_after_data_date is always zero', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'act_start_date' => '2025-01-01 00:00:00']],
    );

    expect((new ScheduleHealthChecker)->check($data, null)['actual_start_after_data_date'])->toBe(0);
});

test('runs against real XER fixture without error', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new ScheduleHealthChecker)->check($data, '2024-01-15');

    // Missing logic: six open tasks that have a predecessor but no successor. The fixture's WBS
    // summaries and completed activities (both start milestones among them) are not checked, and
    // each of its finish milestones has a predecessor.
    expect($result['total_activities'])->toBeGreaterThan(0)
        ->and($result['missing_resources'])->toBe(150)
        ->and($result['missing_logic'])->toBe(6);
});

// ---------------------------------------------------------------
// A missing lag counts as zero
// ---------------------------------------------------------------

test('null lag_hr_cnt falls back to 0 and counts as neither lag nor lead', function () {
    $data = makeHealthData(
        relationships: [['task_pred_id' => '1', 'task_id' => 'T2', 'pred_task_id' => 'T1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '']],
    );

    $result = (new ScheduleHealthChecker)->check($data);

    expect($result['lags_count'])->toBe(0)
        ->and($result['leads_count'])->toBe(0);
});

// ---------------------------------------------------------------
// Empty task_type
// ---------------------------------------------------------------

test('empty task_type causes activity to be counted as missing logic', function () {
    // task_type is '' — not a milestone, so it counts as missing logic
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'task_type' => '']],
    );

    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(1);
});

// ---------------------------------------------------------------
// Empty successor / predecessor ids
// ---------------------------------------------------------------

test('relationship with empty successor string gives no activity a predecessor', function () {
    // task_id = '' registers nothing
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'task_type' => 'TT_Task']],
        relationships: [['task_pred_id' => '99', 'task_id' => '', 'pred_task_id' => '', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0']],
    );

    // Activity 1 still has no predecessor
    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(1);
});

test('relationship with empty predecessor string still gives its successor a predecessor', function () {
    // pred_task_id = '' registers no successor, but task_id = '1' still gets a predecessor.
    // A second link gives activity 1 its successor, so the blank link is the only
    // source of its predecessor.
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'task_type' => 'TT_Task']],
        relationships: [
            ['task_pred_id' => '99', 'task_id' => '1', 'pred_task_id' => '', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0'],
            ['task_pred_id' => '98', 'task_id' => '2', 'pred_task_id' => '1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0'],
        ],
    );

    // Activity 1 has a predecessor and a successor, so it is not missing logic
    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(0);
});

// ---------------------------------------------------------------
// v2.1 — drill-down lists
// ---------------------------------------------------------------

test('offending activities travel as drill-down lists alongside the counts', function () {
    $data = new ParsedXerData(
        header: '',
        tables: collect([
            'TASK' => collect([
                ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'No logic no resources', 'status_code' => 'TK_NotStart'],
                ['task_id' => '2', 'task_code' => 'A200', 'task_name' => 'Started after data date', 'status_code' => 'TK_Active', 'act_start_date' => '2024-07-01'],
            ]),
            'TASKPRED' => collect([
                ['pred_task_id' => '98', 'task_id' => '2', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0'],
                ['pred_task_id' => '2', 'task_id' => '99', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0'],
            ]),
            'TASKRSRC' => collect([
                ['task_id' => '2', 'rsrc_id' => '5'],
            ]),
            'PROJWBS' => collect([]),
        ])
    );

    $result = (new ScheduleHealthChecker)->check($data, '2024-06-01');

    expect($result['missing_logic'])->toBe(1)
        ->and($result['missing_logic_activities'])->toHaveCount(1)
        ->and($result['missing_logic_activities'][0]['task_code'])->toBe('A100')
        ->and($result['missing_resources_activities'][0]['task_code'])->toBe('A100')
        ->and($result['actual_start_after_data_date'])->toBe(1)
        ->and($result['actual_start_after_data_date_activities'][0]['task_code'])->toBe('A200')
        ->and($result['details_limit'])->toBe(200);
});

test('the missing logic list is capped at the details limit while the count stays complete', function () {
    $tasks = array_map(
        fn (int $n) => ['task_id' => (string) $n, 'task_code' => "A{$n}", 'status_code' => 'TK_NotStart', 'task_type' => 'TT_Task'],
        range(1, 250),
    );

    $result = (new ScheduleHealthChecker)->check(makeHealthData(tasks: $tasks));

    expect($result['missing_logic'])->toBe(250)
        ->and($result['missing_logic_activities'])->toHaveCount(200)
        ->and($result['missing_logic_activities'][0]['task_code'])->toBe('A1')
        ->and($result['missing_logic_activities'][199]['task_code'])->toBe('A200');
});

// ---------------------------------------------------------------
// One missing-logic rule for the health check and DCMA point 1
// ---------------------------------------------------------------

test('the health check and the DCMA logic point flag the same activities', function () {
    $task = fn (string $id, string $status = 'TK_NotStart', string $type = 'TT_Task') => [
        'task_id' => $id, 'task_code' => "A{$id}", 'status_code' => $status, 'task_type' => $type,
    ];
    $link = fn (string $pred, string $succ) => [
        'task_pred_id' => "{$pred}-{$succ}", 'pred_task_id' => $pred, 'task_id' => $succ, 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0',
    ];

    $data = makeHealthData(
        tasks: [
            $task('1', type: 'TT_Mile'),                      // start milestone with a successor: fine
            $task('2'),                                       // predecessors 1 and 4, successors 3, 8 and 9: linked
            $task('3'),                                       // no successor
            $task('4'),                                       // no predecessor
            $task('5'),                                       // neither
            $task('6', status: 'TK_Complete'),                // completed: exempt
            $task('7', status: 'TK_Active', type: 'TT_WBS'),  // WBS summary: exempt
            $task('8', type: 'TT_FinMile'),                   // finish milestone with a predecessor: fine
            $task('9', type: 'TT_Mile'),                      // start milestone with no successor
            $task('10', type: 'TT_FinMile'),                  // finish milestone with no predecessor
        ],
        relationships: [$link('1', '2'), $link('4', '2'), $link('2', '3'), $link('2', '8'), $link('2', '9'), $link('10', '3')],
    );

    $health = (new ScheduleHealthChecker)->check($data);
    $dcma = (new DCMA14PointChecker)->calculate($data->getActivities(), null, $data->getRelationships());
    $logic = collect($dcma['points'])->firstWhere('key', 'missing_logic');

    expect($health['missing_logic'])->toBe(5)
        ->and($logic['count'])->toBe($health['missing_logic'])
        ->and(array_column($health['missing_logic_activities'], 'task_code'))->toBe(['A3', 'A4', 'A5', 'A9', 'A10']);
});
