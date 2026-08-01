<?php

use TeckFuture\P6Analyzer\Analyzers\DCMA14PointChecker;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\DTOs\RelationshipData;
use TeckFuture\P6Analyzer\IO\XerParser;

test('it calculates DCMA score from real XER data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new DCMA14PointChecker)->calculate($data->getActivities());

    expect($result)->toHaveKeys(['total_activities', 'incomplete_activities', 'negative_float_count', 'overall_score'])
        ->and($result['overall_score'])->toBeGreaterThanOrEqual(0)
        ->and($result['overall_score'])->toBeLessThanOrEqual(100);
});

test('it accepts a data date for date validation', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new DCMA14PointChecker)->calculate($data->getActivities(), '2024-01-15');

    expect($result)->toHaveKey('invalid_dates_count');
});

test('it clamps score between 0 and 100', function () {
    $result = (new DCMA14PointChecker)->calculate(collect());

    expect($result['overall_score'])->toBeGreaterThanOrEqual(0)
        ->and($result['overall_score'])->toBeLessThanOrEqual(100);
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
    $activities = collect([
        makeDcmaPointActivity([], ['task_id' => '1']),
        makeDcmaPointActivity(['task_code' => 'A2'], ['task_id' => '2']),
        makeDcmaPointActivity(['task_code' => 'A3'], ['task_id' => '3']),
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
