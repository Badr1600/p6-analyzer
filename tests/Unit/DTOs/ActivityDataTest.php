<?php

use TeckFuture\P6Analyzer\DTOs\ActivityData;

test('it creates from a raw array without raw_data key', function () {
    $row = [
        'task_id' => '123',
        'task_code' => 'A100',
        'task_name' => 'Install Pipes',
        'status_code' => 'TK_Active',
        'total_float_hr_cnt' => '16',
        'target_drtn_hr_cnt' => '80',
    ];

    $activity = ActivityData::fromModelOrArray($row);

    expect($activity->id)->toBe('123')
        ->and($activity->task_code)->toBe('A100')
        ->and($activity->name)->toBe('Install Pipes')
        ->and($activity->status_code)->toBe('In Progress')
        ->and($activity->total_float_hr_cnt)->toBe(16.0)
        ->and($activity->target_drtn_hr_cnt)->toBe(80.0);
});

test('it creates from array with raw_data key', function () {
    $data = [
        'id' => '456',
        'task_code' => 'B200',
        'name' => 'Pour Concrete',
        'raw_data' => [
            'status_code' => 'TK_Complete',
            'total_float_hr_cnt' => '0',
            'target_cost' => '5000.50',
        ],
    ];

    $activity = ActivityData::fromModelOrArray($data);

    expect($activity->id)->toBe('456')
        ->and($activity->status_code)->toBe('Completed')
        ->and($activity->target_cost)->toBe(5000.50);
});

test('it handles missing fields gracefully', function () {
    $activity = ActivityData::fromModelOrArray([]);

    expect($activity->id)->toBe('')
        ->and($activity->task_code)->toBe('')
        ->and($activity->name)->toBe('')
        ->and($activity->status_code)->toBeNull()
        ->and($activity->total_float_hr_cnt)->toBeNull()
        ->and($activity->target_start_date)->toBeNull()
        ->and($activity->target_cost)->toBeNull();
});

test('it normalizes P6 status codes', function () {
    expect(ActivityData::normalizeStatus('TK_Complete'))->toBe('Completed')
        ->and(ActivityData::normalizeStatus('TK_Active'))->toBe('In Progress')
        ->and(ActivityData::normalizeStatus('TK_NotStart'))->toBe('Not Started')
        ->and(ActivityData::normalizeStatus('Completed'))->toBe('Completed')
        ->and(ActivityData::normalizeStatus('In Progress'))->toBe('In Progress')
        ->and(ActivityData::normalizeStatus(null))->toBeNull()
        ->and(ActivityData::normalizeStatus(''))->toBeNull();
});

test('it parses dates correctly', function () {
    $row = [
        'task_id' => '1',
        'act_start_date' => '2024-03-15 08:00',
        'target_end_date' => '2024-06-30',
    ];

    $activity = ActivityData::fromModelOrArray($row);

    expect($activity->act_start_date)->not->toBeNull()
        ->and($activity->act_start_date->toDateString())->toBe('2024-03-15')
        ->and($activity->target_end_date->toDateString())->toBe('2024-06-30')
        ->and($activity->act_end_date)->toBeNull();
});

test('it handles zero and empty string cost values', function () {
    $row = [
        'task_id' => '1',
        'target_cost' => '0',
        'act_cost' => '',
    ];

    $activity = ActivityData::fromModelOrArray($row);

    expect($activity->target_cost)->toBe(0.0)
        ->and($activity->act_cost)->toBeNull();
});

test('it creates from an object with raw_data property', function () {
    $obj = new stdClass;
    $obj->id = '789';
    $obj->task_code = 'C300';
    $obj->name = 'Weld Joints';
    $obj->raw_data = [
        'status_code' => 'TK_NotStart',
        'total_float_hr_cnt' => '40',
    ];

    $activity = ActivityData::fromModelOrArray($obj);

    expect($activity->id)->toBe('789')
        ->and($activity->name)->toBe('Weld Joints')
        ->and($activity->status_code)->toBe('Not Started')
        ->and($activity->total_float_hr_cnt)->toBe(40.0);
});
