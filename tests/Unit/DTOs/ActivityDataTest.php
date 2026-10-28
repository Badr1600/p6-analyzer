<?php

use Carbon\Carbon;
use TeckFuture\P6Analyzer\DTOs\ActivityData;

covers(ActivityData::class);

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

// ---------------------------------------------------------------
// parseDate — Carbon passthrough
// ---------------------------------------------------------------

test('Carbon instance is returned as-is without re-parsing', function () {
    $carbon = Carbon::parse('2024-06-15');

    $obj = new stdClass;
    $obj->id = '1';
    $obj->raw_data = ['act_start_date' => $carbon];

    $activity = ActivityData::fromModelOrArray($obj);

    // Must return the same date value — Carbon passthrough branch
    expect($activity->act_start_date)->not->toBeNull()
        ->and($activity->act_start_date->toDateString())->toBe('2024-06-15');
});

test('empty string date field returns null (not parsed)', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'act_start_date' => '',
    ]);

    expect($activity->act_start_date)->toBeNull();
});

test('invalid date string returns null', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'act_start_date' => 'not-a-date-at-all-!!!',
    ]);

    expect($activity->act_start_date)->toBeNull();
});

test('planned_value is read from rawData when present', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'planned_value' => '250.50',
        'target_drtn_hr_cnt' => '40',
    ]);

    expect($activity->planned_value)->toBe(250.50);
});

test('planned_value is null when absent from rawData', function () {
    $activity = ActivityData::fromModelOrArray(['task_id' => '1']);

    expect($activity->planned_value)->toBeNull();
});

// ---------------------------------------------------------------
// toNullableFloat — cast and null paths
// ---------------------------------------------------------------

test('string float is cast to float type', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'total_float_hr_cnt' => '24.5',
    ]);

    expect($activity->total_float_hr_cnt)->toBe(24.5)
        ->and($activity->total_float_hr_cnt)->toBeFloat();
});

test('zero string casts to 0.0 not null', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'total_float_hr_cnt' => '0',
    ]);

    expect($activity->total_float_hr_cnt)->toBe(0.0);
});

test('empty string float field returns null', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'total_float_hr_cnt' => '',
    ]);

    expect($activity->total_float_hr_cnt)->toBeNull();
});

test('null float field returns null', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'total_float_hr_cnt' => null,
    ]);

    expect($activity->total_float_hr_cnt)->toBeNull();
});

// ---------------------------------------------------------------
// complete_pct
// ---------------------------------------------------------------

test('complete_pct is read from raw data', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'complete_pct' => '75',
        'target_drtn_hr_cnt' => '40',
    ]);

    expect($activity->complete_pct)->toBe(75.0);
});

test('phys_comp_pct and complete_pct are read independently when both are set', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'phys_comp_pct' => '90',
        'complete_pct' => '50',
        'target_drtn_hr_cnt' => '40',
    ]);

    expect($activity->phys_comp_pct)->toBe(90.0)
        ->and($activity->complete_pct)->toBe(50.0);
});

// ---------------------------------------------------------------
// status fallback — tests the status_code ?? status chain
// ---------------------------------------------------------------

test('reads status from status key when status_code is absent', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'status' => 'TK_Complete', // fallback key
        'target_drtn_hr_cnt' => '40',
    ]);

    expect($activity->status_code)->toBe('Completed');
});

test('object status property is read when status_code is absent', function () {
    $obj = new stdClass;
    $obj->id = '1';
    $obj->raw_data = [];
    $obj->status = 'TK_NotStart';

    $activity = ActivityData::fromModelOrArray($obj);

    expect($activity->status_code)->toBe('Not Started');
});

// ---------------------------------------------------------------
// act_cost
// ---------------------------------------------------------------

test('act_cost is read from rawData when present', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'act_cost' => '175.25',
        'target_drtn_hr_cnt' => '40',
    ]);

    expect($activity->act_cost)->toBe(175.25);
});

test('act_cost is null when absent', function () {
    $activity = ActivityData::fromModelOrArray(['task_id' => '1']);

    expect($activity->act_cost)->toBeNull();
});

// ---------------------------------------------------------------
// Date and numeric fields are read from rawData
// ---------------------------------------------------------------

test('act_end_date is read from rawData when present', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'act_end_date' => '2024-06-30 00:00:00',
        'target_drtn_hr_cnt' => '40',
    ]);

    expect($activity->act_end_date)->not->toBeNull()
        ->and($activity->act_end_date->toDateString())->toBe('2024-06-30');
});

test('target_start_date is read from rawData when present', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'target_start_date' => '2024-01-15 00:00:00',
        'target_drtn_hr_cnt' => '40',
    ]);

    expect($activity->target_start_date)->not->toBeNull()
        ->and($activity->target_start_date->toDateString())->toBe('2024-01-15');
});

test('target_end_date is read from rawData when present', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'target_end_date' => '2024-03-31 00:00:00',
        'target_drtn_hr_cnt' => '40',
    ]);

    expect($activity->target_end_date)->not->toBeNull()
        ->and($activity->target_end_date->toDateString())->toBe('2024-03-31');
});

test('target_cost is read from rawData when present', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'target_cost' => '9999.99',
        'target_drtn_hr_cnt' => '40',
    ]);

    expect($activity->target_cost)->toBe(9999.99);
});

test('target_drtn_hr_cnt is read from rawData when present', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'target_drtn_hr_cnt' => '120',
    ]);

    expect($activity->target_drtn_hr_cnt)->toBe(120.0);
});

// ---------------------------------------------------------------
// Object path — name defaults to an empty string
// ---------------------------------------------------------------

test('object with no name property returns empty string name', function () {
    $obj = new stdClass;
    $obj->id = '1';
    $obj->raw_data = []; // no task_name in rawData either
    // $obj->name is absent → null ?? '' = ''

    $activity = ActivityData::fromModelOrArray($obj);

    expect($activity->name)->toBe('');
});

test('object with null name property returns empty string', function () {
    $obj = new stdClass;
    $obj->id = '1';
    $obj->name = null;
    $obj->raw_data = [];

    $activity = ActivityData::fromModelOrArray($obj);

    expect($activity->name)->toBe('');
});

// ---------------------------------------------------------------
// name resolution: task_name, then name, then rawData task_name
// ---------------------------------------------------------------

test('task_name is used when name is absent and rawData has no task_name', function () {
    // The top-level task_name is used even though raw_data holds no task_name
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'task_name' => 'My Explicit Task Name',
        'raw_data' => ['task_id' => '1'], // No task_name in raw_data
        // No 'name' key
    ]);

    expect($activity->name)->toBe('My Explicit Task Name');
});

test('task_name takes precedence over name when both are present', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'task_name' => 'From task_name',
        'name' => 'From name key',
        'raw_data' => ['task_id' => '1'],
    ]);

    expect($activity->name)->toBe('From task_name');
});

test('name key is used when task_name is absent', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'name' => 'My Activity Name', // NO task_name key
        'raw_data' => ['task_id' => '1'],  // no task_name in rawData either
    ]);

    expect($activity->name)->toBe('My Activity Name');
});

test('rawData task_name is used when top-level task_name and name are absent', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'raw_data' => ['task_id' => '1', 'task_name' => 'Name From Raw Data'],
        // No task_name, no name at top level
    ]);

    expect($activity->name)->toBe('Name From Raw Data');
});

// ---------------------------------------------------------------
// raw_data extraction and object casts
// ---------------------------------------------------------------

test('array raw_data key takes precedence over full array fallback', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'custom_key' => 'top',
        'raw_data' => ['task_id' => '1', 'custom_key' => 'inner'],
    ]);

    // If it fell back to $activity, custom_key would be 'top'
    expect($activity->raw_data)->toHaveKey('custom_key')
        ->and($activity->raw_data['custom_key'])->toBe('inner');
});

test('object raw_data property is used when set', function () {
    $obj = new stdClass;
    $obj->id = '1';
    $obj->raw_data = ['obj_key' => 'val'];

    $activity = ActivityData::fromModelOrArray($obj);

    expect($activity->raw_data)->toHaveKey('obj_key');
});

test('object raw_data that is not an array is cast to array', function () {
    $obj = new stdClass;
    $obj->id = '1';
    $obj->raw_data = 'string-data';

    $activity = ActivityData::fromModelOrArray($obj);

    // The string is wrapped into an array rather than kept as a string
    expect($activity->raw_data)->toBeArray()->not->toBeEmpty();
});

// ---------------------------------------------------------------
// id, activity_id and task_code resolution
// ---------------------------------------------------------------

test('id key takes precedence over task_id', function () {
    $activity = ActivityData::fromModelOrArray([
        'id' => 'ID_WINS',
        'task_id' => 'TASK_ID_LOSES',
    ]);
    expect($activity->id)->toBe('ID_WINS');
});

test('task_id key takes precedence over rawData task_id', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => 'TOP_TASK_ID',
        'raw_data' => ['task_id' => 'RAW_TASK_ID'],
    ]);
    expect($activity->id)->toBe('TOP_TASK_ID');
});

test('activity_id key takes precedence over rawData activity_id', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'activity_id' => 'TOP_ACT_ID',
        'raw_data' => ['activity_id' => 'RAW_ACT_ID'],
    ]);
    expect($activity->activity_id)->toBe('TOP_ACT_ID');
});

test('task_code key takes precedence over rawData task_code', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'task_code' => 'TOP_CODE',
        'raw_data' => ['task_code' => 'RAW_CODE'],
    ]);
    expect($activity->task_code)->toBe('TOP_CODE');
});

test('rawData task_code is used when top level is absent', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'raw_data' => ['task_code' => 'RAW_CODE_ONLY'],
    ]);
    expect($activity->task_code)->toBe('RAW_CODE_ONLY');
});

// ---------------------------------------------------------------
// Casts and types
// ---------------------------------------------------------------

test('integer id and name are cast to string', function () {
    $activity = ActivityData::fromModelOrArray([
        'id' => 42,
        'task_name' => 100,
    ]);

    expect($activity->id)->toBe('42')->toBeString()
        ->and($activity->name)->toBe('100')->toBeString();
});

test('Carbon instance passed to parseDate is returned exactly as is', function () {
    $carbon = Carbon::parse('2024-01-01');
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'act_start_date' => $carbon, // Passing Carbon object directly
    ]);

    expect($activity->act_start_date)->toBe($carbon);
});

test('float casting for numeric strings', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'target_drtn_hr_cnt' => '40.5',
    ]);

    expect($activity->target_drtn_hr_cnt)->toBe(40.5)->toBeFloat();
});
