<?php

use TeckFuture\P6Analyzer\Analyzers\DCMA14PointChecker;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\DTOs\RelationshipData;
use TeckFuture\P6Analyzer\IO\XerParser;

test('it calculates DCMA score from real XER data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new DCMA14PointChecker)->calculate($data->getActivities());

    // 414 activities are incomplete, 10 of them WBS summaries. 191 of the other 404 have high float:
    // (47.28% - 5%) is deducted from 100
    expect($result)->toHaveKeys(['total_activities', 'incomplete_activities', 'negative_float_count', 'overall_score'])
        ->and($result['incomplete_activities'])->toBe(414)
        ->and($result['high_float_count'])->toBe(191)
        ->and($result['high_float_pct'])->toBe(47.28)
        ->and($result['overall_score'])->toBe(57.72);
});

test('it accepts a data date for date validation', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new DCMA14PointChecker)->calculate($data->getActivities(), '2024-01-15');

    expect($result)->toHaveKey('invalid_dates_count');
});

test('an empty schedule scores 100', function () {
    $result = (new DCMA14PointChecker)->calculate(collect());

    expect($result['overall_score'])->toBe(100);
});

test('it clamps the score at 0 when the penalties exceed 100', function () {
    // Every activity has negative float: 100% x the default weight of 2 is a 200 point penalty
    $activities = collect([
        ActivityData::fromModelOrArray(['task_id' => '1', 'total_float_hr_cnt' => '-8']),
        ActivityData::fromModelOrArray(['task_id' => '2', 'total_float_hr_cnt' => '-16']),
    ]);

    $result = (new DCMA14PointChecker)->calculate($activities);

    expect($result['negative_float_pct'])->toBe(100.0)
        ->and($result['overall_score'])->toBe(0);
});

// ---------------------------------------------------------------
// v2.1 — the 14-point table
// ---------------------------------------------------------------

function makeDcmaPointActivity(array $overrides = [], array $raw = []): ActivityData
{
    return ActivityData::fromModelOrArray(array_merge([
        'id' => $raw['task_id'] ?? '1',
        'task_code' => 'A1',
        'task_name' => 'Task',
        'status_code' => 'TK_NotStart',
        'target_drtn_hr_cnt' => '40',
        'raw_data' => $raw,
    ], $overrides));
}

function makeDcmaRelationship(string $pred, string $succ, string $type = 'PR_FS', string $lag = '0'): RelationshipData
{
    return RelationshipData::fromModelOrArray([
        'id' => "{$pred}-{$succ}",
        'raw_data' => [
            'pred_task_id' => $pred,
            'task_id' => $succ,
            'pred_type' => $type,
            'lag_hr_cnt' => $lag,
        ],
    ]);
}

test('emits all 14 points with four not assessable', function () {
    $result = (new DCMA14PointChecker)->calculate(
        collect([makeDcmaPointActivity()]),
        '2024-06-01',
        collect([]),
    );

    expect($result['points'])->toHaveCount(14)
        ->and(collect($result['points'])->where('assessable', false)->pluck('key')->values()->all())
        ->toContain('resources', 'critical_path_test', 'cpli', 'bei')
        ->and(collect($result['points'])->firstWhere('key', 'bei')['reason'])->toContain('baseline');
});

test('relationship points are not assessable without relationship data', function () {
    $result = (new DCMA14PointChecker)->calculate(collect([makeDcmaPointActivity()]), '2024-06-01');

    $byKey = collect($result['points'])->keyBy('key');
    expect($byKey['missing_logic']['assessable'])->toBeFalse()
        ->and($byKey['leads']['assessable'])->toBeFalse()
        ->and($byKey['hard_constraints']['assessable'])->toBeTrue();
});

test('leads and lags and relationship types are measured', function () {
    // The chain starts at a start milestone and ends at a finish milestone, each linked on the side it needs
    $activities = collect([
        makeDcmaPointActivity([], ['task_id' => '1', 'task_type' => 'TT_Mile']),
        makeDcmaPointActivity(['task_code' => 'A2'], ['task_id' => '2']),
        makeDcmaPointActivity(['task_code' => 'A3'], ['task_id' => '3', 'task_type' => 'TT_FinMile']),
    ]);
    $relationships = collect([
        makeDcmaRelationship('1', '2', 'PR_FS', '0'),
        makeDcmaRelationship('2', '3', 'PR_SS', '-8'), // lead, non-FS
        makeDcmaRelationship('1', '3', 'PR_FS', '16'), // lag
    ]);

    $result = (new DCMA14PointChecker)->calculate($activities, '2024-06-01', $relationships);
    $byKey = collect($result['points'])->keyBy('key');

    expect($byKey['leads']['count'])->toBe(1)
        ->and($byKey['leads']['passed'])->toBeFalse()
        ->and($byKey['lags']['count'])->toBe(1)
        ->and($byKey['relationship_types']['percentage'])->toEqual(66.67)
        ->and($byKey['relationship_types']['passed'])->toBeFalse()
        ->and($byKey['missing_logic']['count'])->toBe(0)
        ->and($byKey['missing_logic']['passed'])->toBeTrue();
});

test('the logic point counts activities missing a predecessor or a successor', function () {
    $activities = collect([
        makeDcmaPointActivity([], ['task_id' => '1']),                     // successor only
        makeDcmaPointActivity(['task_code' => 'A2'], ['task_id' => '2']),  // linked both ways
        makeDcmaPointActivity(['task_code' => 'A3'], ['task_id' => '3']),  // predecessor only
    ]);
    $relationships = collect([
        makeDcmaRelationship('1', '2'),
        makeDcmaRelationship('2', '3'),
    ]);

    $result = (new DCMA14PointChecker)->calculate($activities, '2024-06-01', $relationships);
    $logic = collect($result['points'])->firstWhere('key', 'missing_logic');

    expect($logic['count'])->toBe(2)
        ->and($logic['percentage'])->toBe(66.67)
        ->and($logic['passed'])->toBeFalse();
});

test('completed activities and WBS summaries are exempt from the logic point', function () {
    $activities = collect([
        makeDcmaPointActivity([], ['task_id' => '1', 'status_code' => 'TK_Complete']),
        makeDcmaPointActivity(['task_code' => 'A2'], ['task_id' => '2', 'task_type' => 'TT_WBS']),
    ]);

    $result = (new DCMA14PointChecker)->calculate($activities, '2024-06-01', collect());
    $logic = collect($result['points'])->firstWhere('key', 'missing_logic');

    // Nothing is checked, so the base is empty: 0%, not a division by zero
    expect($logic['count'])->toBe(0)
        ->and($logic['percentage'])->toBe(0.0)
        ->and($logic['passed'])->toBeTrue();
});

test('the logic point percentage is measured against the activities the rule checks', function () {
    // The completed activity and the WBS summary are never checked, so they stay out of the base;
    // the finish milestone is checked (on its open side), so it is in it
    $activities = collect([
        makeDcmaPointActivity([], ['task_id' => '1', 'status_code' => 'TK_Complete']),
        makeDcmaPointActivity(['task_code' => 'A2'], ['task_id' => '2', 'task_type' => 'TT_WBS']),
        makeDcmaPointActivity(['task_code' => 'A3'], ['task_id' => '3']),                               // no links: missing
        makeDcmaPointActivity(['task_code' => 'A4'], ['task_id' => '4']),
        makeDcmaPointActivity(['task_code' => 'A5'], ['task_id' => '5']),
        makeDcmaPointActivity(['task_code' => 'A6'], ['task_id' => '6', 'task_type' => 'TT_FinMile']),  // predecessor only: fine
    ]);
    $relationships = collect([
        makeDcmaRelationship('90', '4'),
        makeDcmaRelationship('4', '5'),
        makeDcmaRelationship('5', '6'),
    ]);

    $result = (new DCMA14PointChecker)->calculate($activities, '2024-06-01', $relationships);
    $logic = collect($result['points'])->firstWhere('key', 'missing_logic');

    // One of the four checked activities, although five activities are incomplete
    expect($result['incomplete_activities'])->toBe(5)
        ->and($logic['count'])->toBe(1)
        ->and($logic['percentage'])->toBe(25.0)
        ->and($logic['passed'])->toBeFalse();
});

test('the logic point states the rule it applies', function () {
    $result = (new DCMA14PointChecker)->calculate(collect([makeDcmaPointActivity()]), '2024-06-01', collect());

    expect(collect($result['points'])->firstWhere('key', 'missing_logic')['threshold_label'])
        ->toBe('≤ 5% missing a predecessor or successor');
});

test('milestones are checked on their open side only', function () {
    // TT_Mile and TT_FinMile are the task types P6 writes for start and finish milestones:
    // a start milestone needs a successor, a finish milestone needs a predecessor
    $activities = collect([
        makeDcmaPointActivity([], ['task_id' => '1', 'task_type' => 'TT_Mile']),                        // successor only: fine
        makeDcmaPointActivity(['task_code' => 'A2'], ['task_id' => '2', 'task_type' => 'TT_Mile']),     // predecessor only: missing
        makeDcmaPointActivity(['task_code' => 'A3'], ['task_id' => '3', 'task_type' => 'TT_FinMile']),  // predecessor only: fine
        makeDcmaPointActivity(['task_code' => 'A4'], ['task_id' => '4', 'task_type' => 'TT_FinMile']),  // successor only: missing
    ]);
    $relationships = collect([
        makeDcmaRelationship('1', '90'),
        makeDcmaRelationship('90', '2'),
        makeDcmaRelationship('90', '3'),
        makeDcmaRelationship('4', '90'),
    ]);

    $result = (new DCMA14PointChecker)->calculate($activities, '2024-06-01', $relationships);
    $logic = collect($result['points'])->firstWhere('key', 'missing_logic');

    expect($logic['count'])->toBe(2)
        ->and($logic['percentage'])->toBe(50.0)
        ->and($logic['passed'])->toBeFalse();
});

test('hard constraints and missed activities are counted', function () {
    $activities = collect([
        makeDcmaPointActivity([], ['task_id' => '1', 'cstr_type' => 'CS_MANDFIN']),
        // Open activity planned to finish before the data date → missed.
        makeDcmaPointActivity(['task_code' => 'A2'], ['task_id' => '2', 'target_end_date' => '2024-05-01']),
        makeDcmaPointActivity(['task_code' => 'A3'], ['task_id' => '3', 'cstr_type' => 'CS_ALAP']),
    ]);

    $result = (new DCMA14PointChecker)->calculate($activities, '2024-06-01');
    $byKey = collect($result['points'])->keyBy('key');

    expect($byKey['hard_constraints']['count'])->toBe(1)
        ->and($byKey['missed_activities']['count'])->toBe(1);
});

test('empty schedule is flagged not assessable', function () {
    $result = (new DCMA14PointChecker)->calculate(collect(), '2024-06-01', collect());

    expect($result['assessable'])->toBeFalse()
        ->and(collect($result['points'])->firstWhere('key', 'negative_float')['passed'])->toBeNull();
});

// ---------------------------------------------------------------
// The population each point counts
// ---------------------------------------------------------------

test('the float, duration and constraint points count incomplete activities only', function () {
    // Eight completed activities were long, pinned to a date and behind; two are still open and clean
    $completed = collect(range(1, 8))->map(fn (int $id) => makeDcmaPointActivity([], [
        'task_id' => (string) $id,
        'status_code' => 'TK_Complete',
        'total_float_hr_cnt' => '-8',
        'target_drtn_hr_cnt' => '400',
        'cstr_type' => 'CS_MSO',
    ]));
    $open = collect(range(9, 10))->map(fn (int $id) => makeDcmaPointActivity([], [
        'task_id' => (string) $id,
        'status_code' => 'TK_NotStart',
        'total_float_hr_cnt' => '0',
        'target_drtn_hr_cnt' => '40',
    ]));

    $result = (new DCMA14PointChecker)->calculate($completed->concat($open));
    $byKey = collect($result['points'])->keyBy('key');

    expect($byKey['hard_constraints']['count'])->toBe(0)
        ->and($byKey['negative_float']['count'])->toBe(0)
        ->and($byKey['high_duration']['count'])->toBe(0)
        ->and($byKey['high_duration']['percentage'])->toBe(0.0)
        ->and($result['negative_float_count'])->toBe(0)
        ->and($result['high_duration_pct'])->toBe(0.0)
        ->and($result['overall_score'])->toBe(100);
});

test('the float, duration and constraint points pass over level of effort and WBS summary activities', function (string $taskType) {
    $activities = collect([
        makeDcmaPointActivity([], ['task_id' => '1', 'task_type' => $taskType, 'total_float_hr_cnt' => '400', 'target_drtn_hr_cnt' => '2000', 'cstr_type' => 'CS_MANDFIN']),
        makeDcmaPointActivity([], ['task_id' => '2', 'task_type' => 'TT_Task', 'total_float_hr_cnt' => '400', 'target_drtn_hr_cnt' => '2000', 'cstr_type' => 'CS_MANDFIN']),
        makeDcmaPointActivity([], ['task_id' => '3', 'task_type' => 'TT_Task', 'total_float_hr_cnt' => '0', 'target_drtn_hr_cnt' => '40']),
    ]);

    $result = (new DCMA14PointChecker)->calculate($activities);
    $byKey = collect($result['points'])->keyBy('key');

    // One of the two task-dependent activities fails each: 50%, not two of three
    expect($byKey['hard_constraints']['count'])->toBe(1)
        ->and($byKey['hard_constraints']['percentage'])->toBe(50.0)
        ->and($byKey['high_float']['count'])->toBe(1)
        ->and($byKey['high_float']['percentage'])->toBe(50.0)
        ->and($byKey['high_duration']['count'])->toBe(1)
        ->and($byKey['high_duration']['percentage'])->toBe(50.0);
})->with(['level of effort' => 'TT_LOE', 'WBS summary' => 'TT_WBS']);

test('a constraint that stops an activity moving later is hard, as a primary or a secondary constraint', function (array $constraint, int $expected) {
    $activities = collect([makeDcmaPointActivity([], ['task_id' => '1', ...$constraint])]);

    $result = (new DCMA14PointChecker)->calculate($activities);

    expect(collect($result['points'])->firstWhere('key', 'hard_constraints')['count'])->toBe($expected);
})->with([
    'mandatory start' => [['cstr_type' => 'CS_MANDSTART'], 1],
    'mandatory finish' => [['cstr_type' => 'CS_MANDFIN'], 1],
    'start on' => [['cstr_type' => 'CS_MSO'], 1],
    'finish on' => [['cstr_type' => 'CS_MEO'], 1],
    'start on or before' => [['cstr_type' => 'CS_MSOB'], 1],
    'finish on or before' => [['cstr_type' => 'CS_MEOB'], 1],
    'a hard secondary constraint' => [['cstr_type' => 'CS_MSOA', 'cstr_type2' => 'CS_MEOB'], 1],
    'hard primary and secondary constraints, once' => [['cstr_type' => 'CS_MSO', 'cstr_type2' => 'CS_MEO'], 1],
    'start on or after' => [['cstr_type' => 'CS_MSOA'], 0],
    'finish on or after' => [['cstr_type' => 'CS_MEOA'], 0],
    'as late as possible' => [['cstr_type' => 'CS_ALAP'], 0],
]);

test('the invalid dates point counts an actual date after the data date and a forecast date before it', function (array $raw, int $expected) {
    $activities = collect([makeDcmaPointActivity([], ['task_id' => '1', ...$raw])]);

    $result = (new DCMA14PointChecker)->calculate($activities, '2024-06-01 08:00');

    expect(collect($result['points'])->firstWhere('key', 'invalid_dates')['count'])->toBe($expected)
        ->and($result['invalid_dates_count'])->toBe($expected);
})->with([
    'started after the data date' => [['status_code' => 'TK_Active', 'act_start_date' => '2024-06-10 08:00'], 1],
    'finished after the data date' => [['status_code' => 'TK_Complete', 'act_start_date' => '2024-05-01 08:00', 'act_end_date' => '2024-06-10 17:00'], 1],
    'started and finished after the data date, once' => [['status_code' => 'TK_Complete', 'act_start_date' => '2024-06-05 08:00', 'act_end_date' => '2024-06-10 17:00'], 1],
    'not started, planned to start before the data date' => [['status_code' => 'TK_NotStart', 'target_start_date' => '2024-05-20 08:00'], 1],
    'in progress, forecast to finish before the data date' => [['status_code' => 'TK_Active', 'act_start_date' => '2024-05-01 08:00', 'early_end_date' => '2024-05-25 17:00'], 1],
    'in progress, remaining finish before the data date' => [['status_code' => 'TK_Active', 'act_start_date' => '2024-05-01 08:00', 'reend_date' => '2024-05-25 17:00'], 1],
    'in progress, forecast to finish after the data date' => [['status_code' => 'TK_Active', 'act_start_date' => '2024-05-01 08:00', 'early_end_date' => '2024-06-25 17:00'], 0],
    'finished before the data date' => [['status_code' => 'TK_Complete', 'act_start_date' => '2024-05-01 08:00', 'act_end_date' => '2024-05-20 17:00', 'early_end_date' => '2024-05-20 17:00'], 0],
    'not started, planned to start after the data date' => [['status_code' => 'TK_NotStart', 'target_start_date' => '2024-06-20 08:00', 'early_end_date' => '2024-06-28 17:00'], 0],
]);

test('the missed activities point measures those finished late or still open against those due by the data date', function () {
    $activities = collect([
        // Due, finished on time
        makeDcmaPointActivity([], ['task_id' => '1', 'status_code' => 'TK_Complete', 'target_end_date' => '2024-05-10 17:00', 'act_end_date' => '2024-05-10 17:00']),
        makeDcmaPointActivity([], ['task_id' => '2', 'status_code' => 'TK_Complete', 'target_end_date' => '2024-05-10 17:00', 'act_end_date' => '2024-05-08 17:00']),
        // Due, finished late
        makeDcmaPointActivity([], ['task_id' => '3', 'status_code' => 'TK_Complete', 'target_end_date' => '2024-05-10 17:00', 'act_end_date' => '2024-05-20 17:00']),
        // Due, still open
        makeDcmaPointActivity([], ['task_id' => '4', 'status_code' => 'TK_Active', 'target_end_date' => '2024-05-10 17:00']),
        // Not due yet: neither missed nor measured against
        makeDcmaPointActivity([], ['task_id' => '5', 'status_code' => 'TK_NotStart', 'target_end_date' => '2024-07-10 17:00']),
        makeDcmaPointActivity([], ['task_id' => '6', 'status_code' => 'TK_NotStart', 'target_end_date' => '2024-07-10 17:00']),
    ]);

    $missed = collect((new DCMA14PointChecker)->calculate($activities, '2024-06-01 08:00')['points'])->firstWhere('key', 'missed_activities');

    expect($missed['count'])->toBe(2)
        ->and($missed['percentage'])->toBe(50.0)
        ->and($missed['passed'])->toBeFalse();
});

test('the missed activities point passes when nothing was due by the data date', function () {
    $activities = collect([makeDcmaPointActivity([], ['task_id' => '1', 'status_code' => 'TK_NotStart', 'target_end_date' => '2024-07-10 17:00'])]);

    $missed = collect((new DCMA14PointChecker)->calculate($activities, '2024-06-01 08:00')['points'])->firstWhere('key', 'missed_activities');

    expect($missed['count'])->toBe(0)
        ->and($missed['percentage'])->toBe(0.0)
        ->and($missed['passed'])->toBeTrue();
});

test('the relationship points pass over a relationship into a completed activity', function () {
    $activities = collect([
        makeDcmaPointActivity([], ['task_id' => '1', 'status_code' => 'TK_Complete']),
        makeDcmaPointActivity([], ['task_id' => '2', 'status_code' => 'TK_Complete']),
        makeDcmaPointActivity([], ['task_id' => '3', 'status_code' => 'TK_NotStart']),
        makeDcmaPointActivity([], ['task_id' => '4', 'status_code' => 'TK_NotStart']),
    ]);
    $relationships = collect([
        // Into a completed activity: history, whatever its lag and type
        makeDcmaRelationship('1', '2', 'PR_SS', '-8'),
        makeDcmaRelationship('1', '2', 'PR_FF', '16'),
        // Into open activities
        makeDcmaRelationship('2', '3', 'PR_FS', '0'),
        makeDcmaRelationship('3', '4', 'PR_FS', '0'),
    ]);

    $byKey = collect((new DCMA14PointChecker)->calculate($activities, '2024-06-01', $relationships)['points'])->keyBy('key');

    expect($byKey['leads']['count'])->toBe(0)
        ->and($byKey['leads']['passed'])->toBeTrue()
        ->and($byKey['lags']['count'])->toBe(0)
        ->and($byKey['relationship_types']['count'])->toBe(2)
        ->and($byKey['relationship_types']['percentage'])->toBe(100.0);
});

// ---------------------------------------------------------------
// The data date: to the minute with a time, the whole day without
// ---------------------------------------------------------------

test('an actual finish on the data date is valid when the data date has no time', function (string $dataDate, int $expected) {
    $activities = collect([makeDcmaPointActivity([], [
        'task_id' => '1',
        'status_code' => 'TK_Complete',
        'act_start_date' => '2024-06-01 08:00',
        'act_end_date' => '2024-06-01 17:00',
    ])]);

    $result = (new DCMA14PointChecker)->calculate($activities, $dataDate);

    expect($result['invalid_dates_count'])->toBe($expected);
})->with([
    'no time: the day has not ended' => ['2024-06-01', 0],
    'a time before the actual dates' => ['2024-06-01 07:00', 1],
    'a time between the actual start and finish' => ['2024-06-01 12:00', 1],
    'a time after the actual finish' => ['2024-06-01 17:30', 0],
    'the day before, with no time' => ['2024-05-31', 1],
]);

test('a date forecast on the data date is valid when the data date has no time', function (string $dataDate, int $expected) {
    $activities = collect([makeDcmaPointActivity([], [
        'task_id' => '1',
        'status_code' => 'TK_NotStart',
        'target_start_date' => '2024-06-01 08:00',
        'early_end_date' => '2024-06-01 17:00',
    ])]);

    $result = (new DCMA14PointChecker)->calculate($activities, $dataDate);

    expect($result['invalid_dates_count'])->toBe($expected);
})->with([
    'no time: the day has not begun' => ['2024-06-01', 0],
    'a time after the planned start' => ['2024-06-01 09:00', 1],
    'the day after, with no time' => ['2024-06-02', 1],
]);

test('an activity planned to finish on the data date is due', function (string $dataDate, int $expected) {
    $activities = collect([makeDcmaPointActivity([], [
        'task_id' => '1',
        'status_code' => 'TK_Active',
        'act_start_date' => '2024-05-01 08:00',
        'target_end_date' => '2024-06-01 17:00',
        'early_end_date' => '2024-06-10 17:00',
    ])]);

    $missed = collect((new DCMA14PointChecker)->calculate($activities, $dataDate)['points'])->firstWhere('key', 'missed_activities');

    expect($missed['count'])->toBe($expected);
})->with([
    'no time: due that day' => ['2024-06-01', 1],
    'at its planned finish' => ['2024-06-01 17:00', 1],
    'a time before its planned finish' => ['2024-06-01 16:00', 0],
    'the day before, with no time' => ['2024-05-31', 0],
]);

test('an activity finished on its planned day, later in the day, is not missed', function () {
    $activities = collect([makeDcmaPointActivity([], [
        'task_id' => '1',
        'status_code' => 'TK_Complete',
        'target_end_date' => '2024-05-10 08:00',
        'act_end_date' => '2024-05-10 17:00',
    ])]);

    $missed = collect((new DCMA14PointChecker)->calculate($activities, '2024-06-01')['points'])->firstWhere('key', 'missed_activities');

    expect($missed['count'])->toBe(0)
        ->and($missed['percentage'])->toBe(0.0);
});

test('the invalid dates and missed activities points pass over level of effort and WBS summary activities', function (string $taskType) {
    $late = ['status_code' => 'TK_Active', 'act_start_date' => '2024-06-20 08:00', 'target_end_date' => '2024-05-10 17:00'];
    $activities = collect([
        makeDcmaPointActivity([], ['task_id' => '1', 'task_type' => $taskType, ...$late]),
        makeDcmaPointActivity([], ['task_id' => '2', 'task_type' => 'TT_Task', ...$late]),
    ]);

    $result = (new DCMA14PointChecker)->calculate($activities, '2024-06-01');
    $byKey = collect($result['points'])->keyBy('key');

    // The rolled-up activity is counted as an activity, and as incomplete, but not by the points
    expect($result['total_activities'])->toBe(2)
        ->and($result['incomplete_activities'])->toBe(2)
        ->and($byKey['invalid_dates']['count'])->toBe(1)
        ->and($byKey['invalid_dates']['percentage'])->toBe(100.0)
        ->and($byKey['missed_activities']['count'])->toBe(1)
        ->and($byKey['missed_activities']['percentage'])->toBe(100.0);
})->with(['level of effort' => 'TT_LOE', 'WBS summary' => 'TT_WBS']);

test('the relationship points pass over a relationship into a level of effort or WBS summary activity', function () {
    $activities = collect([
        makeDcmaPointActivity([], ['task_id' => '1']),
        makeDcmaPointActivity([], ['task_id' => '2']),
        makeDcmaPointActivity([], ['task_id' => '3', 'task_type' => 'TT_LOE']),
        makeDcmaPointActivity([], ['task_id' => '4', 'task_type' => 'TT_WBS']),
    ]);
    $relationships = collect([
        makeDcmaRelationship('1', '2', 'PR_FS', '0'),
        // A level of effort hangs on its neighbours by start and finish links, often with a lag
        makeDcmaRelationship('1', '3', 'PR_SS', '16'),
        makeDcmaRelationship('1', '4', 'PR_SS', '-8'),
    ]);

    $byKey = collect((new DCMA14PointChecker)->calculate($activities, '2024-06-01', $relationships)['points'])->keyBy('key');

    expect($byKey['leads']['count'])->toBe(0)
        ->and($byKey['lags']['count'])->toBe(0)
        ->and($byKey['relationship_types']['count'])->toBe(1)
        ->and($byKey['relationship_types']['percentage'])->toBe(100.0);
});

test('the relationship points count a relationship that names no successor, or one outside the schedule', function () {
    // A completed activity with no id must not make the relationship with no successor disappear
    $activities = collect([
        makeDcmaPointActivity(['id' => ''], ['status_code' => 'TK_Complete']),
        makeDcmaPointActivity([], ['task_id' => '2']),
    ]);
    $relationships = collect([
        makeDcmaRelationship('2', '', 'PR_FS', '-8'),
        makeDcmaRelationship('2', '999', 'PR_FS', '16'),
    ]);

    $byKey = collect((new DCMA14PointChecker)->calculate($activities, '2024-06-01', $relationships)['points'])->keyBy('key');

    expect($byKey['leads']['count'])->toBe(1)
        ->and($byKey['lags']['count'])->toBe(1)
        ->and($byKey['relationship_types']['count'])->toBe(2);
});

test('an activity with no status counts as not complete', function () {
    $activities = collect([makeDcmaPointActivity([], [
        'task_id' => '1',
        'total_float_hr_cnt' => '-8',
        'target_end_date' => '2024-05-10 17:00',
        'act_start_date' => '2024-05-01 08:00',
        'act_end_date' => '2024-05-09 17:00',
    ])]);

    $result = (new DCMA14PointChecker)->calculate($activities, '2024-06-01');
    $byKey = collect($result['points'])->keyBy('key');

    expect($result['incomplete_activities'])->toBe(1)
        ->and($byKey['negative_float']['count'])->toBe(1)
        ->and($byKey['missed_activities']['count'])->toBe(1);
});

test('the example schedule\'s activity points at a data date', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $byKey = collect((new DCMA14PointChecker)->calculate($data->getActivities(), '2024-01-15', $data->getRelationships())['points'])->keyBy('key');

    expect($byKey['hard_constraints']['count'])->toBe(0)
        ->and($byKey['invalid_dates']['count'])->toBe(42)
        ->and($byKey['invalid_dates']['percentage'])->toBe(9.88)
        ->and($byKey['missed_activities']['count'])->toBe(0)
        ->and($byKey['missed_activities']['passed'])->toBeTrue();
});
