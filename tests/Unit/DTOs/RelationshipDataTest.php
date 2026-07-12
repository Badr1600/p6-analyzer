<?php

use TeckFuture\P6Analyzer\DTOs\RelationshipData;

// ---------------------------------------------------------------
// Basic construction from array
// ---------------------------------------------------------------

test('creates from flat array with task_pred_id as id', function () {
    $rel = RelationshipData::fromModelOrArray([
        'task_pred_id' => '99',
        'task_id' => '200',
        'pred_task_id' => '100',
        'pred_type' => 'PR_FS',
        'lag_hr_cnt' => '8',
    ]);

    expect($rel->id)->toBe('99')
        ->and($rel->pred_type)->toBe('PR_FS')
        ->and($rel->lag_hr_cnt)->toBe(8.0)
        ->and($rel->raw_data['task_id'])->toBe('200')
        ->and($rel->raw_data['pred_task_id'])->toBe('100');
});

test('creates from flat array with id key taking precedence', function () {
    // Kills CoalesceRemoveLeft: $relationship['id'] ?? ... removing id fallback
    $rel = RelationshipData::fromModelOrArray([
        'id' => 'EXPLICIT_ID',
        'task_pred_id' => 'PRED_ID',
        'pred_type' => 'PR_SS',
        'lag_hr_cnt' => '0',
    ]);

    expect($rel->id)->toBe('EXPLICIT_ID');
});

test('falls back to task_pred_id when id is absent', function () {
    // Kills DecrementInteger/IncrementInteger on fallback chain
    $rel = RelationshipData::fromModelOrArray([
        'task_pred_id' => 'PRED_ID_99',
        'pred_type' => 'PR_FF',
    ]);

    expect($rel->id)->toBe('PRED_ID_99');
});

test('falls back to rawData task_pred_id as last resort', function () {
    // Kills CoalesceRemoveLeft removing rawData['task_pred_id']
    $rel = RelationshipData::fromModelOrArray([
        'raw_data' => ['task_pred_id' => 'RAW_PRED_ID', 'pred_type' => 'PR_SF'],
    ]);

    expect($rel->id)->toBe('RAW_PRED_ID');
});

test('id defaults to empty string when all id sources are absent', function () {
    // Kills EmptyStringToNotEmpty mutations
    $rel = RelationshipData::fromModelOrArray([]);

    expect($rel->id)->toBe('');
});

// ---------------------------------------------------------------
// pred_type from rawData (kills CoalesceRemoveLeft on line 29)
// ---------------------------------------------------------------

test('pred_type is read from rawData', function () {
    $rel = RelationshipData::fromModelOrArray([
        'task_pred_id' => '1',
        'pred_type' => 'PR_FS',
    ]);

    expect($rel->pred_type)->toBe('PR_FS');
});

test('pred_type defaults to empty string when absent', function () {
    // Kills EmptyStringToNotEmpty on pred_type fallback
    $rel = RelationshipData::fromModelOrArray(['task_pred_id' => '1']);

    expect($rel->pred_type)->toBe('');
});

test('pred_type with explicit raw_data key', function () {
    $rel = RelationshipData::fromModelOrArray([
        'task_pred_id' => '1',
        'raw_data' => ['pred_type' => 'PR_FF', 'task_pred_id' => '1'],
    ]);

    expect($rel->pred_type)->toBe('PR_FF');
});

// ---------------------------------------------------------------
// lag_hr_cnt cast and null (kills RemoveDoubleCast line 30, ContinueToBreak)
// ---------------------------------------------------------------

test('lag_hr_cnt is parsed as float', function () {
    $rel = RelationshipData::fromModelOrArray(['task_pred_id' => '1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '8']);

    expect($rel->lag_hr_cnt)->toBe(8.0)->toBeFloat();
});

test('lag_hr_cnt fractional value is cast correctly', function () {
    // Kills RemoveDoubleCast: (float) '4.5' → '4.5'
    $rel = RelationshipData::fromModelOrArray(['task_pred_id' => '1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '4.5']);

    expect($rel->lag_hr_cnt)->toBe(4.5)->toBeFloat();
});

test('lag_hr_cnt empty string returns null', function () {
    $rel = RelationshipData::fromModelOrArray(['task_pred_id' => '1', 'pred_type' => 'PR_SS', 'lag_hr_cnt' => '']);

    expect($rel->lag_hr_cnt)->toBeNull();
});

test('lag_hr_cnt absent returns null', function () {
    $rel = RelationshipData::fromModelOrArray(['task_pred_id' => '1', 'pred_type' => 'PR_FS']);

    expect($rel->lag_hr_cnt)->toBeNull();
});

test('lag_hr_cnt zero is a valid float', function () {
    $rel = RelationshipData::fromModelOrArray(['task_pred_id' => '1', 'pred_type' => 'PR_FF', 'lag_hr_cnt' => '0']);

    expect($rel->lag_hr_cnt)->toBe(0.0);
});

test('lag_hr_cnt negative value', function () {
    $rel = RelationshipData::fromModelOrArray(['task_pred_id' => '1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '-8']);

    expect($rel->lag_hr_cnt)->toBe(-8.0);
});

// ---------------------------------------------------------------
// id string cast (kills RemoveStringCast on line 28)
// ---------------------------------------------------------------

test('integer id is cast to string', function () {
    // Kills RemoveStringCast
    $rel = RelationshipData::fromModelOrArray([
        'task_pred_id' => 42, // integer
        'pred_type' => 'PR_FS',
    ]);

    expect($rel->id)->toBe('42')->toBeString();
});

// ---------------------------------------------------------------
// pred_type string cast (kills RemoveStringCast on line 29)
// ---------------------------------------------------------------

test('non-string pred_type is cast to string', function () {
    // Kills RemoveStringCast on pred_type
    $rel = RelationshipData::fromModelOrArray([
        'task_pred_id' => '1',
        'pred_type' => 100, // integer
    ]);

    expect($rel->pred_type)->toBe('100')->toBeString();
});

// ---------------------------------------------------------------
// Object path (kills TernaryNegated, RemoveArrayCast, etc.)
// ---------------------------------------------------------------

test('creates from object with raw_data property', function () {
    $obj = new stdClass;
    $obj->id = '55';
    $obj->raw_data = ['pred_type' => 'PR_SF', 'lag_hr_cnt' => '16'];

    $rel = RelationshipData::fromModelOrArray($obj);

    expect($rel->id)->toBe('55')
        ->and($rel->pred_type)->toBe('PR_SF')
        ->and($rel->lag_hr_cnt)->toBe(16.0);
});

test('object with array raw_data is used directly', function () {
    // Kills RemoveArrayCast: is_array(array) → always true, raw_data used
    $obj = new stdClass;
    $obj->id = 'OBJ1';
    $obj->raw_data = ['pred_type' => 'PR_FS', 'lag_hr_cnt' => '5', 'custom_field' => 'value'];

    $rel = RelationshipData::fromModelOrArray($obj);

    expect($rel->raw_data)->toHaveKey('custom_field')
        ->and($rel->raw_data['custom_field'])->toBe('value');
});

test('object with null raw_data returns empty array', function () {
    // Kills CoalesceRemoveLeft on (array)($obj->raw_data ?? [])
    $obj = new stdClass;
    $obj->id = 'OBJ2';
    $obj->raw_data = null;

    $rel = RelationshipData::fromModelOrArray($obj);

    expect($rel->raw_data)->toBe([])
        ->and($rel->pred_type)->toBe('');
});

test('object with non-array raw_data is cast to array', function () {
    // Kills RemoveArrayCast: (array) $scalar → [$scalar]
    $obj = new stdClass;
    $obj->id = 'OBJ3';
    $obj->raw_data = 'some-string';

    $rel = RelationshipData::fromModelOrArray($obj);

    // (array)'some-string' = [0 => 'some-string'] — non-empty
    expect($rel->raw_data)->not->toBeEmpty();
});

// ---------------------------------------------------------------
// raw_data retention
// ---------------------------------------------------------------

test('raw_data retains all original fields from array input', function () {
    $row = [
        'task_pred_id' => '1',
        'task_id' => 'T1',
        'pred_task_id' => 'T2',
        'pred_type' => 'PR_FS',
        'lag_hr_cnt' => '4',
        'comments' => 'test comment',
    ];

    $rel = RelationshipData::fromModelOrArray($row);

    expect($rel->raw_data)->toHaveKey('comments')
        ->and($rel->raw_data['comments'])->toBe('test comment');
});

// ---------------------------------------------------------------
// Missing fields (kills EmptyStringToNotEmpty for id/pred_type)
// ---------------------------------------------------------------

test('missing all fields returns empty strings and null lag', function () {
    $rel = RelationshipData::fromModelOrArray([]);

    expect($rel->id)->toBe('')
        ->and($rel->pred_type)->toBe('')
        ->and($rel->lag_hr_cnt)->toBeNull();
});
