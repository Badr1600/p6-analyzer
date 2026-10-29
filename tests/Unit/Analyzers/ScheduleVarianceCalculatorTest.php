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
// total_activities_compared reflects MATCHED pairs (task_code join)
// ---------------------------------------------------------------

test('total_activities_compared counts matched pairs, not the raw current set', function () {
    $wb = collect([makeSvcActivity('A1', '2024-06-01', '1'), makeSvcActivity('A2', '2024-06-01', '2')]);
    $master = collect();

    $result = (new ScheduleVarianceCalculator)->calculate($wb, $master);

    expect($result['total_activities_compared'])->toBe(0)
        ->and($result['matched_count'])->toBe(0)
        ->and($result['unmatched_count'])->toBe(2)
        ->and($result['current_activities_count'])->toBe(2);
});

// ---------------------------------------------------------------
// Activity not in master → skipped
// ---------------------------------------------------------------

test('current activity with no matching baseline task_code is skipped', function () {
    $wb = collect([makeSvcActivity('ORPHAN', '2024-06-15', '1')]);
    $master = collect([makeSvcActivity('OTHER', '2024-06-01', '2')]); // task_code A2 ≠ A1

    $result = (new ScheduleVarianceCalculator)->calculate($wb, $master);

    expect($result['slipping_activities_count'])->toBe(0)
        ->and($result['matched_count'])->toBe(0);
});

// ---------------------------------------------------------------
// Matching is by task_code — activity_id is null for imported XER data
// ---------------------------------------------------------------

test('activities match by task_code even when activity_id is null everywhere', function () {
    $make = fn (string $taskCode, string $endDate) => ActivityData::fromModelOrArray([
        'id' => $taskCode,
        'activity_id' => null,
        'task_code' => $taskCode,
        'task_name' => "Task {$taskCode}",
        'status_code' => 'TK_Active',
        'target_drtn_hr_cnt' => '40',
        'raw_data' => ['target_end_date' => $endDate],
    ]);

    $wb = collect([$make('X100', '2024-06-11'), $make('X200', '2024-06-01')]);
    $master = collect([$make('X100', '2024-06-01'), $make('X200', '2024-06-01')]);

    $result = (new ScheduleVarianceCalculator)->calculate($wb, $master);

    expect($result['matched_count'])->toBe(2)
        ->and($result['slipping_activities_count'])->toBe(1)
        ->and($result['slipping_activities'][0]['task_code'])->toBe('X100')
        ->and($result['slipping_activities'][0]['variance_days'])->toEqual(10);
});

// ---------------------------------------------------------------
// Enrichment: signed start variance, status, float
// ---------------------------------------------------------------

test('slipping rows carry signed start variance, status and total float', function () {
    $current = ActivityData::fromModelOrArray([
        'id' => '1', 'activity_id' => null, 'task_code' => 'A1',
        'task_name' => 'T', 'status_code' => 'TK_Active', 'target_drtn_hr_cnt' => '40',
        'raw_data' => [
            'status_code' => 'TK_Active',
            'target_start_date' => '2024-05-25', // starts 5 days EARLY
            'target_end_date' => '2024-06-15',
            'total_float_hr_cnt' => '-8',
        ],
    ]);
    $baseline = ActivityData::fromModelOrArray([
        'id' => '1', 'activity_id' => null, 'task_code' => 'A1',
        'task_name' => 'T', 'status_code' => 'TK_Active', 'target_drtn_hr_cnt' => '40',
        'raw_data' => [
            'target_start_date' => '2024-05-30',
            'target_end_date' => '2024-06-01',
        ],
    ]);

    $entry = (new ScheduleVarianceCalculator)->calculate(collect([$current]), collect([$baseline]))['slipping_activities'][0];

    expect($entry['start_variance_days'])->toBe(-5)
        ->and($entry['variance_days'])->toBe(14)
        ->and($entry['status'])->toBe('In Progress')
        ->and($entry['total_float_hr'])->toEqual(-8.0)
        ->and($entry['master_start'])->toBe('2024-05-30')
        ->and($entry['workbench_start'])->toBe('2024-05-25');
});

// ---------------------------------------------------------------
// Min-slip threshold
// ---------------------------------------------------------------

test('min slip threshold filters small slips out', function () {
    $wb = collect([
        makeSvcActivity('A1', '2024-06-03', '1'),  // 2 days
        makeSvcActivity('A2', '2024-06-11', '2'),  // 10 days
    ]);
    $master = collect([
        makeSvcActivity('A1', '2024-06-01', '1'),
        makeSvcActivity('A2', '2024-06-01', '2'),
    ]);

    $result = (new ScheduleVarianceCalculator)->calculate($wb, $master, 5.0);

    expect($result['slipping_activities_count'])->toBe(1)
        ->and($result['slipping_activities'][0]['task_code'])->toBe('A2')
        ->and($result['matched_count'])->toBe(2);
});

// ---------------------------------------------------------------
// Slippage detection
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
// variance_days calculation
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
// Variance counts calendar days, not elapsed 24-hour periods
// ---------------------------------------------------------------

test('a finish moved a few hours within the same day is not a slip', function () {
    $wb = collect([makeSvcActivity('ACT1', '2024-06-01 20:00')]);     // 12 h later, same date
    $master = collect([makeSvcActivity('ACT1', '2024-06-01 08:00')]);

    $result = (new ScheduleVarianceCalculator)->calculate($wb, $master);

    expect($result['matched_count'])->toBe(1)
        ->and($result['slipping_activities_count'])->toBe(0)
        ->and($result['max_variance_days'])->toBe(0);
});

test('a finish on the next calendar day is a 1 day slip even when less than 24 hours later', function () {
    $wb = collect([makeSvcActivity('ACT1', '2024-06-02 06:00')]);     // 10 h later, next date
    $master = collect([makeSvcActivity('ACT1', '2024-06-01 20:00')]);

    $result = (new ScheduleVarianceCalculator)->calculate($wb, $master);

    expect($result['slipping_activities_count'])->toBe(1)
        ->and($result['slipping_activities'][0]['variance_days'])->toBe(1);
});

test('a slip of a day and a half is 1 day, not rounded up to 2', function () {
    $wb = collect([makeSvcActivity('ACT1', '2024-06-02 20:00')]);     // 36 h later, next date
    $master = collect([makeSvcActivity('ACT1', '2024-06-01 08:00')]);

    $result = (new ScheduleVarianceCalculator)->calculate($wb, $master);

    expect($result['slipping_activities'][0]['variance_days'])->toBe(1)
        ->and($result['max_variance_days'])->toBe(1);
});

test('start variance is a signed count of calendar days', function (string $baselineStart, string $currentStart, int $expectedDays) {
    // The finish slips by 14 days so the activity is listed; only the start differs between cases.
    $make = fn (string $start, string $finish) => ActivityData::fromModelOrArray([
        'id' => '1', 'activity_id' => null, 'task_code' => 'A1',
        'task_name' => 'T', 'status_code' => 'TK_Active', 'target_drtn_hr_cnt' => '40',
        'raw_data' => ['target_start_date' => $start, 'target_end_date' => $finish],
    ]);

    $result = (new ScheduleVarianceCalculator)->calculate(
        collect([$make($currentStart, '2024-06-15')]),
        collect([$make($baselineStart, '2024-06-01')]),
    );

    expect($result['slipping_activities'][0]['start_variance_days'])->toBe($expectedDays);
})->with([
    'same day, 12 hours later' => ['2024-06-03 08:00', '2024-06-03 20:00', 0],
    'same day, 12 hours earlier' => ['2024-06-03 20:00', '2024-06-03 08:00', 0],
    'next day, 10 hours later' => ['2024-06-03 20:00', '2024-06-04 06:00', 1],
    'previous day, 10 hours earlier' => ['2024-06-02 06:00', '2024-06-01 20:00', -1],
    'five days earlier' => ['2024-06-10 08:00', '2024-06-05 17:00', -5],
]);

test('the dates of the schedules being compared are left untouched', function () {
    $wb = makeSvcActivity('ACT1', '2024-06-15 17:30');
    $master = makeSvcActivity('ACT1', '2024-06-01 08:15');

    (new ScheduleVarianceCalculator)->calculate(collect([$wb]), collect([$master]));

    expect($wb->target_end_date->toDateTimeString())->toBe('2024-06-15 17:30:00')
        ->and($master->target_end_date->toDateTimeString())->toBe('2024-06-01 08:15:00');
});

// ---------------------------------------------------------------
// max_variance_days
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
// average_variance_days
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
// A missing finish date never counts as slippage
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

    // A schedule compared with itself: every activity matches its own row and none slips
    expect($result['total_activities_compared'])->toBeGreaterThan(0)
        ->and($result['total_activities_compared'])->toBe($activities->count())
        ->and($result['slipping_activities_count'])->toBe(0);
});
