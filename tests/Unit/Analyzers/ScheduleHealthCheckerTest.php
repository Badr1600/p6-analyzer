<?php

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
        ->and($result['out_of_sequence'])->toBe(0);
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

test('start milestone is excluded from missing logic count', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'task_type' => 'TT_Mile']],
    );

    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(0);
});

test('finish milestone excluded from missing logic', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'task_type' => 'TT_FinMile']],
    );

    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(0);
});

test('activity with predecessor is not missing logic', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '2', 'status_code' => 'TK_Active', 'task_type' => 'TT_Task']],
        relationships: [['task_pred_id' => '99', 'task_id' => '2', 'pred_task_id' => '1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0']],
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
// out_of_sequence
// ---------------------------------------------------------------

test('activity with actual start after data date counted as out of sequence', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'act_start_date' => '2024-03-01 00:00:00']],
    );

    $result = (new ScheduleHealthChecker)->check($data, '2024-01-01');

    expect($result['out_of_sequence'])->toBe(1);
});

test('activity with actual start before data date is not out of sequence', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'act_start_date' => '2023-06-01 00:00:00']],
    );

    $result = (new ScheduleHealthChecker)->check($data, '2024-01-01');

    expect($result['out_of_sequence'])->toBe(0);
});

test('no data date means out_of_sequence is always zero', function () {
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'act_start_date' => '2025-01-01 00:00:00']],
    );

    expect((new ScheduleHealthChecker)->check($data, null)['out_of_sequence'])->toBe(0);
});

test('runs against real XER fixture without error', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new ScheduleHealthChecker)->check($data, '2024-01-15');

    expect($result['total_activities'])->toBeGreaterThan(0)
        ->and($result['missing_resources'])->toBe(150)
        ->and($result['missing_logic'])->toBe(10);
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

test('relationship with empty successor string skips hasPredecessor registration', function () {
    // task_id = '' → falsy, doesn't register as successor
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'task_type' => 'TT_Task']],
        relationships: [['task_pred_id' => '99', 'task_id' => '', 'pred_task_id' => '', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0']],
    );

    // Activity 1 still has no predecessor registered
    expect((new ScheduleHealthChecker)->check($data)['missing_logic'])->toBe(1);
});

test('relationship with empty predecessor string still gives its successor a predecessor', function () {
    // pred_task_id = '' → falsy, so no activity is registered as having a successor,
    // but task_id = '1' is still registered as having a predecessor
    $data = makeHealthData(
        tasks: [['task_id' => '1', 'status_code' => 'TK_Active', 'task_type' => 'TT_Task']],
        relationships: [['task_pred_id' => '99', 'task_id' => '1', 'pred_task_id' => '', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0']],
    );

    // Activity 1 has a predecessor link, so it is not missing logic
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
        ->and($result['out_of_sequence'])->toBe(1)
        ->and($result['out_of_sequence_activities'][0]['task_code'])->toBe('A200')
        ->and($result['details_limit'])->toBe(200);
});
