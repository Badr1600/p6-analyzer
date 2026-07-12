<?php

use TeckFuture\P6Analyzer\Analyzers\CriticalPathCalculator;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\IO\XerParser;

covers(CriticalPathCalculator::class);

function makeActivity(string $id, ?float $float, string $status = 'TK_Active'): ActivityData
{
    return ActivityData::fromModelOrArray([
        'task_id' => $id,
        'task_code' => "A{$id}",
        'task_name' => "Task {$id}",
        'status_code' => $status,
        'total_float_hr_cnt' => $float !== null ? (string) $float : null,
        'target_drtn_hr_cnt' => '40',
    ]);
}

// ---------------------------------------------------------------
// Empty input
// ---------------------------------------------------------------

test('empty collection returns zeros', function () {
    $result = (new CriticalPathCalculator)->calculate(collect());

    expect($result['total_activities'])->toBe(0)
        ->and($result['critical_activities_count'])->toBe(0)
        ->and($result['critical_path_percentage'])->toBe(0)
        ->and($result['critical_activities'])->toBeEmpty();
});

// ---------------------------------------------------------------
// Counting and percentage
// ---------------------------------------------------------------

test('correctly identifies zero-float activities as critical', function () {
    $activities = collect([
        makeActivity('1', 0.0),
        makeActivity('2', 8.0),
        makeActivity('3', -4.0),
    ]);

    $result = (new CriticalPathCalculator)->calculate($activities, 0);

    expect($result['total_activities'])->toBe(3)
        ->and($result['critical_activities_count'])->toBe(2) // 0 and -4 are <= 0
        ->and($result['critical_path_percentage'])->toBe(round(2 / 3 * 100, 2));
});

test('null float activities are excluded from critical count', function () {
    $activities = collect([
        makeActivity('1', null),
        makeActivity('2', 0.0),
    ]);

    $result = (new CriticalPathCalculator)->calculate($activities, 0);

    expect($result['critical_activities_count'])->toBe(1); // null is excluded
});

test('threshold 0 excludes positive float', function () {
    $activities = collect([makeActivity('1', 8.0)]);

    $result = (new CriticalPathCalculator)->calculate($activities, 0);

    expect($result['critical_activities_count'])->toBe(0);
});

test('custom threshold includes activities up to that value', function () {
    $activities = collect([
        makeActivity('1', 0.0),
        makeActivity('2', 8.0),
        makeActivity('3', 16.0),
        makeActivity('4', 24.0),
    ]);

    $result = (new CriticalPathCalculator)->calculate($activities, 8.0);

    // activities with float <= 8.0 = IDs 1 and 2
    expect($result['critical_activities_count'])->toBe(2);
});

test('100% critical when all activities have zero float', function () {
    $activities = collect([
        makeActivity('1', 0.0),
        makeActivity('2', -8.0),
    ]);

    $result = (new CriticalPathCalculator)->calculate($activities, 0);

    expect($result['critical_path_percentage'])->toBe(100.0)
        ->and($result['critical_activities_count'])->toBe(2);
});

test('critical activity entries contain required fields', function () {
    $activities = collect([makeActivity('42', 0.0)]);
    $result = (new CriticalPathCalculator)->calculate($activities, 0);

    expect($result['critical_activities'][0])->toHaveKeys(['id', 'task_code', 'name', 'total_float_hr'])
        ->and($result['critical_activities'][0]['id'])->toBe('42')
        ->and($result['critical_activities'][0]['total_float_hr'])->toBe(0.0);
});

test('critical_path_percentage rounds to 2 decimal places', function () {
    // 1 of 3 = 33.33...%
    $activities = collect([
        makeActivity('1', 0.0),
        makeActivity('2', 8.0),
        makeActivity('3', 16.0),
    ]);

    $result = (new CriticalPathCalculator)->calculate($activities, 0);

    expect($result['critical_path_percentage'])->toBe(33.33);
});

test('accepts plain array input', function () {
    $activities = [makeActivity('1', 0.0), makeActivity('2', 8.0)];
    $result = (new CriticalPathCalculator)->calculate($activities, 0);

    expect($result['total_activities'])->toBe(2);
});

test('runs against real XER fixture without error', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new CriticalPathCalculator)->calculate($data->getActivities(), 0);

    expect($result['total_activities'])->toBeGreaterThan(0)
        ->and($result['critical_path_percentage'])->toBeGreaterThanOrEqual(0)
        ->and($result['critical_path_percentage'])->toBeLessThanOrEqual(100);
});
