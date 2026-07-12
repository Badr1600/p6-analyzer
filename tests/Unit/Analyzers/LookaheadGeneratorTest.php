<?php

use TeckFuture\P6Analyzer\Analyzers\LookaheadGenerator;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\IO\XerParser;

covers(LookaheadGenerator::class);

function makeLookaheadData(array $tasks = []): ParsedXerData
{
    return new ParsedXerData(header: '', tables: collect(['TASK' => collect($tasks)]));
}

// ---------------------------------------------------------------
// Return shape
// ---------------------------------------------------------------

test('empty data returns correct shape with zero activity_count', function () {
    $result = (new LookaheadGenerator)->generate(makeLookaheadData(), '2024-01-01', 3);

    expect($result)->toHaveKeys(['data_date', 'lookahead_weeks', 'end_date', 'activity_count', 'activities'])
        ->and($result['data_date'])->toBe('2024-01-01')
        ->and($result['lookahead_weeks'])->toBe(3)
        ->and($result['activity_count'])->toBe(0)
        ->and($result['activities'])->toBeEmpty();
});

test('end_date is exactly weeks ahead of data_date', function () {
    $result = (new LookaheadGenerator)->generate(makeLookaheadData(), '2024-01-01', 3);

    // 3 weeks from 2024-01-01 = 2024-01-22
    expect($result['end_date'])->toBe('2024-01-22');
});

test('end_date changes with different week count', function () {
    $twoWeeks = (new LookaheadGenerator)->generate(makeLookaheadData(), '2024-01-01', 2);
    $sixWeeks = (new LookaheadGenerator)->generate(makeLookaheadData(), '2024-01-01', 6);

    expect($twoWeeks['end_date'])->toBe('2024-01-15')
        ->and($sixWeeks['end_date'])->toBe('2024-02-12')
        ->and($twoWeeks['lookahead_weeks'])->toBe(2)
        ->and($sixWeeks['lookahead_weeks'])->toBe(6);
});

// ---------------------------------------------------------------
// Completed activities are excluded
// ---------------------------------------------------------------

test('completed activities are excluded from lookahead', function () {
    $data = makeLookaheadData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Complete', 'target_start_date' => '2024-01-05', 'target_end_date' => '2024-01-10', 'target_drtn_hr_cnt' => '40'],
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    expect($result['activity_count'])->toBe(0);
});

// ---------------------------------------------------------------
// Window inclusion logic
// ---------------------------------------------------------------

test('activity starting within window is included', function () {
    // window: 2024-01-01 to 2024-01-22, activity starts 2024-01-10
    $data = makeLookaheadData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Active', 'task_code' => 'A100', 'task_name' => 'Test', 'target_start_date' => '2024-01-10 00:00:00', 'target_end_date' => '2024-01-20 00:00:00', 'target_drtn_hr_cnt' => '80'],
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    expect($result['activity_count'])->toBe(1)
        ->and($result['activities'][0]['task_code'])->toBe('A100');
});

test('activity finishing within window is included', function () {
    // window: 2024-01-01 to 2024-01-22, activity started before, finishes on 2024-01-15
    $data = makeLookaheadData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Active', 'task_code' => 'B100', 'task_name' => 'Finish In Window', 'target_start_date' => '2023-12-01 00:00:00', 'target_end_date' => '2024-01-15 00:00:00', 'target_drtn_hr_cnt' => '80'],
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    expect($result['activity_count'])->toBe(1)
        ->and($result['activities'][0]['task_code'])->toBe('B100');
});

test('activity spanning window is included', function () {
    // Starts before, ends after window
    $data = makeLookaheadData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Active', 'task_code' => 'C100', 'task_name' => 'Spans', 'target_start_date' => '2023-12-01 00:00:00', 'target_end_date' => '2024-03-01 00:00:00', 'target_drtn_hr_cnt' => '800'],
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    expect($result['activity_count'])->toBe(1)
        ->and($result['activities'][0]['task_code'])->toBe('C100');
});

test('activity completely outside window is excluded', function () {
    // Far in the future
    $data = makeLookaheadData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Active', 'target_start_date' => '2025-06-01 00:00:00', 'target_end_date' => '2025-07-01 00:00:00', 'target_drtn_hr_cnt' => '80'],
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    expect($result['activity_count'])->toBe(0);
});

// ---------------------------------------------------------------
// Activity entry shape
// ---------------------------------------------------------------

test('activity entry has required keys', function () {
    $data = makeLookaheadData(tasks: [
        ['task_id' => '42', 'status_code' => 'TK_Active', 'task_code' => 'A100', 'task_name' => 'Demo', 'target_start_date' => '2024-01-10 00:00:00', 'target_end_date' => '2024-01-15 00:00:00', 'target_drtn_hr_cnt' => '40'],
    ]);

    $entry = (new LookaheadGenerator)->generate($data, '2024-01-01', 3)['activities'][0];

    expect($entry)->toHaveKeys(['id', 'task_code', 'name', 'start_date', 'finish_date', 'status', 'total_float'])
        ->and($entry['id'])->toBe('42')
        ->and($entry['task_code'])->toBe('A100')
        ->and($entry['start_date'])->toBe('2024-01-10');
});

// ---------------------------------------------------------------
// Sorting
// ---------------------------------------------------------------

test('activities are sorted by start_date ascending', function () {
    $data = makeLookaheadData(tasks: [
        ['task_id' => '2', 'status_code' => 'TK_Active', 'task_code' => 'B', 'target_start_date' => '2024-01-15 00:00:00', 'target_end_date' => '2024-01-20 00:00:00', 'target_drtn_hr_cnt' => '40'],
        ['task_id' => '1', 'status_code' => 'TK_Active', 'task_code' => 'A', 'target_start_date' => '2024-01-05 00:00:00', 'target_end_date' => '2024-01-10 00:00:00', 'target_drtn_hr_cnt' => '40'],
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    expect($result['activities'][0]['task_code'])->toBe('A')
        ->and($result['activities'][1]['task_code'])->toBe('B');
});

// ---------------------------------------------------------------
// Real fixture
// ---------------------------------------------------------------

test('runs against real XER fixture without error', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    expect($result['lookahead_weeks'])->toBe(3)
        ->and($result['activity_count'])->toBeGreaterThanOrEqual(0);
});

// ---------------------------------------------------------------
// Week count boundary (kills DecrementInteger/IncrementInteger on default 3)
// ---------------------------------------------------------------

test('default weeks is 3 (end_date is 3 weeks ahead when not specified)', function () {
    // Kills DecrementInteger: default 3 → 2 (end would be 2024-01-15 not 2024-01-22)
    // Kills IncrementInteger: default 3 → 4 (end would be 2024-01-29 not 2024-01-22)
    $result = (new LookaheadGenerator)->generate(makeLookaheadData(), '2024-01-01');
    // Note: no explicit weeks argument — tests the DEFAULT value

    expect($result['end_date'])->toBe('2024-01-22')
        ->and($result['lookahead_weeks'])->toBe(3);
});

test('1 week window ends exactly 7 days ahead', function () {

    // Kills IncrementInteger: weeks=1 would still give 1-week window but wrong if mutated to 2
    $result = (new LookaheadGenerator)->generate(makeLookaheadData(), '2024-01-01', 1);

    expect($result['end_date'])->toBe('2024-01-08')
        ->and($result['lookahead_weeks'])->toBe(1);
});

test('activity just at window end is included', function () {
    // Finish = exactly the endDate — kills GreaterToGreaterOrEqual/DecrementInteger on window
    $data = makeLookaheadData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Active', 'task_code' => 'E100',
            'task_name' => 'At End', 'target_start_date' => '2024-01-22 00:00:00', // exactly endDate
            'target_end_date' => '2024-01-22 00:00:00', 'target_drtn_hr_cnt' => '8'],
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    expect($result['activity_count'])->toBe(1);
});

test('activity just before window start is excluded', function () {
    // start = 2023-12-31, end = 2023-12-31 → before window start 2024-01-01
    $data = makeLookaheadData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Active', 'task_code' => 'PRE',
            'target_start_date' => '2023-12-28 00:00:00',
            'target_end_date' => '2023-12-31 00:00:00', 'target_drtn_hr_cnt' => '40'],
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    expect($result['activity_count'])->toBe(0);
});

// ---------------------------------------------------------------
// Span check BooleanAndToBooleanOr (line 37)
// ---------------------------------------------------------------

test('activity that starts before but finishes before window does not span', function () {
    // start < windowStart, end < windowStart → spans = false (both conditions must be true)
    // BooleanAndToBooleanOr would make it spans = true if either condition is true
    $data = makeLookaheadData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Active', 'task_code' => 'NO_SPAN',
            'target_start_date' => '2023-11-01 00:00:00', // before window
            'target_end_date' => '2023-12-15 00:00:00',   // also before window → no span
            'target_drtn_hr_cnt' => '80'],
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    expect($result['activity_count'])->toBe(0);
});

test('activity starting after window end does not span', function () {
    // start > windowEnd → startsBefore = false, finishes after = true → spans = false (needs both)
    $data = makeLookaheadData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Active', 'task_code' => 'FUTURE',
            'target_start_date' => '2024-03-01 00:00:00', // after window
            'target_end_date' => '2024-06-01 00:00:00',   // way after window
            'target_drtn_hr_cnt' => '80'],
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    expect($result['activity_count'])->toBe(0);
});

// ---------------------------------------------------------------
// Activity without target dates uses act_start/end dates
// ---------------------------------------------------------------

test('activity with only act_start_date falls back to act dates', function () {
    $data = makeLookaheadData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Active', 'task_code' => 'ACT_DATE',
            'task_name' => 'Act Date Task',
            'act_start_date' => '2024-01-10 00:00:00', // in window
            'act_end_date' => '2024-01-15 00:00:00',
            'target_drtn_hr_cnt' => '40'],
        // No target_start_date/target_end_date
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    expect($result['activity_count'])->toBe(1)
        ->and($result['activities'][0]['task_code'])->toBe('ACT_DATE');
});

// ---------------------------------------------------------------
// Break/Continue and sorting mutations
// ---------------------------------------------------------------

test('Completed activity does not break the loop', function () {
    // Kills ContinueToBreak line 29
    $data = makeLookaheadData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Complete', 'target_start_date' => '2024-01-10 00:00:00', 'target_end_date' => '2024-01-15 00:00:00', 'target_drtn_hr_cnt' => '40'],
        ['task_id' => '2', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-10 00:00:00', 'target_end_date' => '2024-01-15 00:00:00', 'target_drtn_hr_cnt' => '40'],
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    // If it was `break`, task 2 would be skipped
    expect($result['activity_count'])->toBe(1);
});

test('null start date with valid end date uses null-safe operator', function () {
    // Kills RemoveNullSafeOperator on line 44 ($start?->toDateString())
    $data = makeLookaheadData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Active',
            'target_end_date' => '2024-01-10 00:00:00', // Finishes in window
            'target_drtn_hr_cnt' => '40'],
        // No start dates provided
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    expect($result['activity_count'])->toBe(1)
        ->and($result['activities'][0]['start_date'])->toBeNull();
});

test('null end date with valid start date uses null-safe operator', function () {
    // Kills RemoveNullSafeOperator on line 45 ($finish?->toDateString())
    $data = makeLookaheadData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Active',
            'target_start_date' => '2024-01-10 00:00:00', // Starts in window
            'target_drtn_hr_cnt' => '40'],
        // No end dates provided
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    expect($result['activity_count'])->toBe(1)
        ->and($result['activities'][0]['finish_date'])->toBeNull();
});

test('activities without start dates are sorted to the end', function () {
    // Kills IfNegated / RemoveNot on line 53 (`if (! $a['start_date']) return 1;`)
    $data = makeLookaheadData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Active', 'task_code' => 'NO_START', 'target_end_date' => '2024-01-10 00:00:00'],
        ['task_id' => '2', 'status_code' => 'TK_Active', 'task_code' => 'HAS_START', 'target_start_date' => '2024-01-10 00:00:00'],
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    // HAS_START should come before NO_START
    expect($result['activities'][0]['task_code'])->toBe('HAS_START')
        ->and($result['activities'][1]['task_code'])->toBe('NO_START');
});

test('spansWindow requires both start and finish to be truthy', function () {
    // Kills BooleanAndToBooleanOr on line 37: $start && $finish && ...
    $data = makeLookaheadData(tasks: [
        ['task_id' => '1', 'status_code' => 'TK_Active',
            'target_start_date' => '2023-12-01 00:00:00', // Starts before window
            // No end date
        ],
    ]);

    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    // If `$start || $finish` was evaluated, this might evaluate to true and get included
    expect($result['activity_count'])->toBe(0);
});
