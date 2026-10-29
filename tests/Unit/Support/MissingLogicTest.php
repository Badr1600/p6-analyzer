<?php

use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\DTOs\RelationshipData;
use TeckFuture\P6Analyzer\Support\MissingLogic;

covers(MissingLogic::class);

function makeLogicActivity(string $id, string $status = 'TK_NotStart', string $type = 'TT_Task'): ActivityData
{
    return ActivityData::fromModelOrArray([
        'task_id' => $id,
        'task_code' => "A{$id}",
        'status_code' => $status,
        'task_type' => $type,
    ]);
}

/** A finish-to-start link: $pred is the predecessor of $succ. */
function makeLogicLink(string $pred, string $succ): RelationshipData
{
    return RelationshipData::fromModelOrArray([
        'task_pred_id' => "{$pred}-{$succ}",
        'pred_task_id' => $pred,
        'task_id' => $succ,
        'pred_type' => 'PR_FS',
        'lag_hr_cnt' => '0',
    ]);
}

/**
 * The task codes MissingLogic flags, in the order it returns them.
 *
 * @param  array<int, ActivityData>  $activities
 * @param  array<int, RelationshipData>  $links
 * @return array<int, string>
 */
function missingLogicCodes(array $activities, array $links): array
{
    return MissingLogic::find(collect($activities), collect($links))
        ->map(fn (ActivityData $activity) => $activity->task_code)
        ->all();
}

test('an activity with neither a predecessor nor a successor is missing logic', function () {
    expect(missingLogicCodes([makeLogicActivity('1')], []))->toBe(['A1']);
});

test('an activity with a predecessor but no successor is missing logic', function () {
    expect(missingLogicCodes([makeLogicActivity('1')], [makeLogicLink('9', '1')]))->toBe(['A1']);
});

test('an activity with a successor but no predecessor is missing logic', function () {
    expect(missingLogicCodes([makeLogicActivity('1')], [makeLogicLink('1', '9')]))->toBe(['A1']);
});

test('an activity with both a predecessor and a successor is not missing logic', function () {
    $links = [makeLogicLink('8', '1'), makeLogicLink('1', '9')];

    expect(missingLogicCodes([makeLogicActivity('1')], $links))->toBe([]);
});

test('a completed activity is not missing logic', function () {
    expect(missingLogicCodes([makeLogicActivity('1', status: 'TK_Complete')], []))->toBe([]);
});

test('a WBS summary activity is not missing logic', function () {
    expect(missingLogicCodes([makeLogicActivity('1', type: 'TT_WBS')], []))->toBe([]);
});

test('a start milestone with only a successor is not missing logic', function () {
    // Nothing comes before a start milestone
    expect(missingLogicCodes([makeLogicActivity('1', type: 'TT_Mile')], [makeLogicLink('1', '9')]))->toBe([]);
});

test('a start milestone with only a predecessor is missing logic', function () {
    expect(missingLogicCodes([makeLogicActivity('1', type: 'TT_Mile')], [makeLogicLink('9', '1')]))->toBe(['A1']);
});

test('a finish milestone with only a predecessor is not missing logic', function () {
    // Nothing comes after a finish milestone
    expect(missingLogicCodes([makeLogicActivity('1', type: 'TT_FinMile')], [makeLogicLink('9', '1')]))->toBe([]);
});

test('a finish milestone with only a successor is missing logic', function () {
    expect(missingLogicCodes([makeLogicActivity('1', type: 'TT_FinMile')], [makeLogicLink('1', '9')]))->toBe(['A1']);
});

test('a milestone with no links at all is missing logic', function (string $type) {
    expect(missingLogicCodes([makeLogicActivity('1', type: $type)], []))->toBe(['A1']);
})->with([
    'start milestone' => 'TT_Mile',
    'finish milestone' => 'TT_FinMile',
]);

test('a completed milestone is not missing logic', function (string $type) {
    expect(missingLogicCodes([makeLogicActivity('1', status: 'TK_Complete', type: $type)], []))->toBe([]);
})->with([
    'start milestone' => 'TT_Mile',
    'finish milestone' => 'TT_FinMile',
]);

test('the offenders come back in schedule order', function () {
    $activities = [makeLogicActivity('3'), makeLogicActivity('1'), makeLogicActivity('2')];

    expect(missingLogicCodes($activities, [makeLogicLink('1', '2')]))->toBe(['A3', 'A1', 'A2']);
});
