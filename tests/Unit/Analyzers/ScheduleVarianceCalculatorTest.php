<?php

use TeckFuture\P6Analyzer\Analyzers\ScheduleVarianceCalculator;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\IO\XerParser;

covers(ScheduleVarianceCalculator::class);

function makeSvcActivity(string $activityId, string $endDate, string $id = '1'): ActivityData
{
    return ActivityData::fromModelOrArray([
        'id' => $id,
        'activity_id' => $activityId,
        'task_code' => "A{$id}",
        'task_name' => "Task {$id}",
        'status_code' => 'TK_Active',
        'target_drtn_hr_cnt' => '40',
        'raw_data' => ['target_end_date' => $endDate],
    ]);
}

// ---------------------------------------------------------------
// Empty input
// ---------------------------------------------------------------

test('empty collections return zero counts', function () {
    $result = (new ScheduleVarianceCalculator)->calculate(collect(), collect());

    expect($result['total_activities_compared'])->toBe(0)
        ->and($result['slipping_activities_count'])->toBe(0)
        ->and($result['average_variance_days'])->toEqual(0)
        ->and($result['max_variance_days'])->toBe(0)
        ->and($result['slipping_activities'])->toBeEmpty();
});

// ---------------------------------------------------------------
// total_activities_compared reflects workbench count
// ---------------------------------------------------------------

test('total_activities_compared equals workbench count regardless of matches', function () {
    $wb = collect([makeSvcActivity('A1', '2024-06-01', '1'), makeSvcActivity('A2', '2024-06-01', '2')]);
    $master = collect();

    $result = (new ScheduleVarianceCalculator)->calculate($wb, $master);

    expect($result['total_activities_compared'])->toBe(2);
});

// ---------------------------------------------------------------
// Activity not in master → skipped
// ---------------------------------------------------------------

test('workbench activity with no matching master is skipped', function () {
    $wb = collect([makeSvcActivity('ORPHAN', '2024-06-15')]);
    $master = collect([makeSvcActivity('OTHER', '2024-06-01')]);

    $result = (new ScheduleVarianceCalculator)->calculate($wb, $master);

    expect($result['slipping_activities_count'])->toBe(0);
});

// ---------------------------------------------------------------
// Slippage detection — kills BooleanAndToBooleanOr
// ---------------------------------------------------------------

test('activity with same finish as master does not slip', function () {
    $activities = collect([makeSvcActivity('ACT1', '2024-06-01')]);

    $result = (new ScheduleVarianceCalculator)->calculate($activities, $activities);

    expect($result['slipping_activities_count'])->toBe(0);
});

test('activity finishing earlier than master does not slip', function () {
    $wb = collect([makeSvcActivity('ACT1', '2024-05-01')]);     // earlier
    $master = collect([makeSvcActivity('ACT1', '2024-06-01')]); // later

    $result = (new ScheduleVarianceCalculator)->calculate($wb, $master);

    expect($result['slipping_activities_count'])->toBe(0);
});

test('activity finishing later than master is slipping', function () {
    $wb = collect([makeSvcActivity('ACT1', '2024-06-15')]);     // 14 days late
    $master = collect([makeSvcActivity('ACT1', '2024-06-01')]);

    $result = (new ScheduleVarianceCalculator)->calculate($wb, $master);

    expect($result['slipping_activities_count'])->toBe(1)
        ->and($result['slipping_activities'][0]['variance_days'])->toEqual(14);
});

// ---------------------------------------------------------------
// variance_days calculation — kills DecrementInteger / IncrementInteger
// ---------------------------------------------------------------

test('variance_days is exactly 7 for one week slip', function () {
    $wb = collect([makeSvcActivity('ACT1', '2024-06-08')]);     // 7 days late
    $master = collect([makeSvcActivity('ACT1', '2024-06-01')]);

    $result = (new ScheduleVarianceCalculator)->calculate($wb, $master);

    expect($result['slipping_activities'][0]['variance_days'])->toEqual(7)
        ->and($result['max_variance_days'])->toEqual(7);
});

test('variance_days is exactly 30 for one month slip', function () {
    $wb = collect([makeSvcActivity('ACT1', '2024-07-01')]);     // 30 days late
    $master = collect([makeSvcActivity('ACT1', '2024-06-01')]);

    $result = (new ScheduleVarianceCalculator)->calculate($wb, $master);

    expect($result['slipping_activities'][0]['variance_days'])->toEqual(30);
});

// ---------------------------------------------------------------
// max_variance_days — kills DecrementInteger on comparison
// ---------------------------------------------------------------

test('max_variance_days tracks the worst slip', function () {
    $wb = collect([
        makeSvcActivity('A1', '2024-06-08', '1'),  // 7 days late
        makeSvcActivity('A2', '2024-06-22', '2'),  // 21 days late
        makeSvcActivity('A3', '2024-06-05', '3'),  // 4 days late
    ]);
    $master = collect([
        makeSvcActivity('A1', '2024-06-01', '1'),
        makeSvcActivity('A2', '2024-06-01', '2'),
        makeSvcActivity('A3', '2024-06-01', '3'),
    ]);

    $result = (new ScheduleVarianceCalculator)->calculate($wb, $master);

    expect($result['max_variance_days'])->toEqual(21)
        ->and($result['slipping_activities_count'])->toBe(3);
});

// ---------------------------------------------------------------
// average_variance_days — kills DecrementInteger on round and divide
// ---------------------------------------------------------------

test('average_variance_days is 0 when no slipping activities', function () {
    $activities = collect([makeSvcActivity('ACT1', '2024-06-01')]);

    expect((new ScheduleVarianceCalculator)->calculate($activities, $activities)['average_variance_days'])->toEqual(0);
});

test('average_variance_days is correctly computed over multiple slips', function () {
    // 7 + 21 = 28 total, / 2 = 14 average
    $wb = collect([
        makeSvcActivity('A1', '2024-06-08', '1'),  // 7 days
        makeSvcActivity('A2', '2024-06-22', '2'),  // 21 days
    ]);
    $master = collect([
        makeSvcActivity('A1', '2024-06-01', '1'),
        makeSvcActivity('A2', '2024-06-01', '2'),
    ]);

    $result = (new ScheduleVarianceCalculator)->calculate($wb, $master);

    expect($result['average_variance_days'])->toEqual(14.0);
});

// ---------------------------------------------------------------
// Slipping activity entry shape
// ---------------------------------------------------------------

test('slipping activity entry has required keys', function () {
    $wb = collect([makeSvcActivity('ACT1', '2024-06-15', '42')]);
    $master = collect([makeSvcActivity('ACT1', '2024-06-01', '42')]);

    $entry = (new ScheduleVarianceCalculator)->calculate($wb, $master)['slipping_activities'][0];

    expect($entry)->toHaveKeys(['id', 'task_code', 'name', 'master_finish', 'workbench_finish', 'variance_days'])
        ->and($entry['master_finish'])->toBe('2024-06-01')
        ->and($entry['workbench_finish'])->toBe('2024-06-15');
});

// ---------------------------------------------------------------
// Date nullity guard — kills BooleanAndToBooleanOr
// ---------------------------------------------------------------

test('activity with null workbench finish does not slip', function () {
    $wb = ActivityData::fromModelOrArray([
        'id' => '1', 'activity_id' => 'ACT1', 'task_code' => 'A',
        'task_name' => 'T', 'status_code' => 'TK_Active', 'target_drtn_hr_cnt' => '40',
        'raw_data' => [], // no target_end_date
    ]);
    $master = collect([makeSvcActivity('ACT1', '2024-06-01')]);

    $result = (new ScheduleVarianceCalculator)->calculate(collect([$wb]), $master);

    expect($result['slipping_activities_count'])->toBe(0);
});

test('accepts plain array input', function () {
    $result = (new ScheduleVarianceCalculator)->calculate([], []);

    expect($result['total_activities_compared'])->toBe(0);
});

test('runs against real XER fixture without error', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $activities = $data->getActivities();
    $result = (new ScheduleVarianceCalculator)->calculate($activities, $activities);

    // Same data — slipping count may vary but total compared equals activity count
    expect($result['total_activities_compared'])->toBeGreaterThan(0)
        ->and($result['slipping_activities_count'])->toBeGreaterThanOrEqual(0);
});
