<?php

use TeckFuture\P6Analyzer\Analyzers\ScheduleVarianceCalculator;
use TeckFuture\P6Analyzer\DTOs\ActivityData;

test('same activities with matching IDs yield zero slippage', function () {
    // Build controlled test data with matching activity_id values
    $activities = collect([
        ActivityData::fromModelOrArray([
            'id' => '1',
            'activity_id' => 'ACT1',
            'task_code' => 'A100',
            'name' => 'Test',
            'raw_data' => ['target_end_date' => '2024-06-01'],
        ]),
    ]);

    $result = (new ScheduleVarianceCalculator)->calculate($activities, $activities);

    expect($result['slipping_activities_count'])->toBe(0)
        ->and($result['average_variance_days'])->toBe(0);
});

test('it detects slippage when workbench finishes later', function () {
    $master = collect([
        ActivityData::fromModelOrArray([
            'id' => '1', 'activity_id' => 'ACT1', 'task_code' => 'A100',
            'raw_data' => ['target_end_date' => '2024-06-01'],
        ]),
    ]);

    $workbench = collect([
        ActivityData::fromModelOrArray([
            'id' => '1', 'activity_id' => 'ACT1', 'task_code' => 'A100',
            'raw_data' => ['target_end_date' => '2024-06-15'],
        ]),
    ]);

    $result = (new ScheduleVarianceCalculator)->calculate($workbench, $master);

    expect($result['slipping_activities_count'])->toBe(1)
        ->and($result['slipping_activities'])->toHaveCount(1)
        ->and($result['slipping_activities'][0]['variance_days'])->toEqual(14);
});

test('it handles empty collections', function () {
    $result = (new ScheduleVarianceCalculator)->calculate(collect(), collect());

    expect($result['total_activities_compared'])->toBe(0)
        ->and($result['slipping_activities_count'])->toBe(0);
});
