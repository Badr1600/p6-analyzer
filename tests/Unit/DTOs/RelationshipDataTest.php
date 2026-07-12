<?php

use TeckFuture\P6Analyzer\DTOs\RelationshipData;

test('it creates from a raw array', function () {
    $row = [
        'task_pred_id' => '99',
        'task_id' => '200',
        'pred_task_id' => '100',
        'pred_type' => 'PR_FS',
        'lag_hr_cnt' => '8',
    ];

    $rel = RelationshipData::fromModelOrArray($row);

    expect($rel->id)->toBe('99')
        ->and($rel->pred_type)->toBe('PR_FS')
        ->and($rel->lag_hr_cnt)->toBe(8.0)
        ->and($rel->raw_data['task_id'])->toBe('200')
        ->and($rel->raw_data['pred_task_id'])->toBe('100');
});

test('it handles empty lag as null', function () {
    $rel = RelationshipData::fromModelOrArray(['task_pred_id' => '1', 'pred_type' => 'PR_SS', 'lag_hr_cnt' => '']);

    expect($rel->lag_hr_cnt)->toBeNull();
});

test('it handles zero lag', function () {
    $rel = RelationshipData::fromModelOrArray(['task_pred_id' => '1', 'pred_type' => 'PR_FF', 'lag_hr_cnt' => '0']);

    expect($rel->lag_hr_cnt)->toBe(0.0);
});

test('it handles negative lag (leads)', function () {
    $rel = RelationshipData::fromModelOrArray(['task_pred_id' => '1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '-8']);

    expect($rel->lag_hr_cnt)->toBe(-8.0);
});

test('it handles missing fields gracefully', function () {
    $rel = RelationshipData::fromModelOrArray([]);

    expect($rel->id)->toBe('')
        ->and($rel->pred_type)->toBe('')
        ->and($rel->lag_hr_cnt)->toBeNull();
});

test('it creates from an object with raw_data property', function () {
    $obj = new stdClass;
    $obj->id = '55';
    $obj->raw_data = [
        'pred_type' => 'PR_SF',
        'lag_hr_cnt' => '16',
    ];

    $rel = RelationshipData::fromModelOrArray($obj);

    expect($rel->id)->toBe('55')
        ->and($rel->pred_type)->toBe('PR_SF')
        ->and($rel->lag_hr_cnt)->toBe(16.0);
});

test('raw_data retains all original fields', function () {
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
