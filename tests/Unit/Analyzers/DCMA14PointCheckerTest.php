<?php

use TeckFuture\P6Analyzer\Analyzers\DCMA14PointChecker;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\DTOs\RelationshipData;
use TeckFuture\P6Analyzer\IO\XerParser;

test('it calculates DCMA score from real XER data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new DCMA14PointChecker)->calculate($data->getActivities());

    // 193 of the 414 incomplete activities have high float: (46.62% - 5%) is deducted from 100
    expect($result)->toHaveKeys(['total_activities', 'incomplete_activities', 'negative_float_count', 'overall_score'])
        ->and($result['overall_score'])->toBe(58.38);
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
        'activity_id' => null,
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
    // The chain starts at a milestone and ends at one, which keeps both ends exempt from the logic point
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

    expect($logic['count'])->toBe(0)
        ->and($logic['passed'])->toBeTrue();
});

test('the logic point states the rule it applies', function () {
    $result = (new DCMA14PointChecker)->calculate(collect([makeDcmaPointActivity()]), '2024-06-01', collect());

    expect(collect($result['points'])->firstWhere('key', 'missing_logic')['threshold_label'])
        ->toBe('≤ 5% missing a predecessor or successor');
});

test('start and finish milestones are exempt from the logic point', function () {
    // TT_Mile and TT_FinMile are the task types P6 writes for start and finish milestones
    $activities = collect([
        makeDcmaPointActivity([], ['task_id' => '1', 'task_type' => 'TT_Mile']),
        makeDcmaPointActivity(['task_code' => 'A2'], ['task_id' => '2', 'task_type' => 'TT_FinMile']),
    ]);

    $result = (new DCMA14PointChecker)->calculate($activities, '2024-06-01', collect());
    $logic = collect($result['points'])->firstWhere('key', 'missing_logic');

    expect($logic['count'])->toBe(0)
        ->and($logic['passed'])->toBeTrue();
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
