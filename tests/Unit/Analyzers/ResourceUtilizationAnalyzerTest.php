<?php

use Illuminate\Support\Facades\Config;
use TeckFuture\P6Analyzer\Analyzers\ResourceUtilizationAnalyzer;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\IO\XerParser;

covers(ResourceUtilizationAnalyzer::class);

function makeResourceData(array $tasks = [], array $taskrsrc = [], array $rsrc = []): ParsedXerData
{
    return new ParsedXerData(header: '', tables: collect([
        'TASK' => collect($tasks),
        'TASKRSRC' => collect($taskrsrc),
        'RSRC' => collect($rsrc),
    ]));
}

// ---------------------------------------------------------------
// Empty / missing data
// ---------------------------------------------------------------

test('empty data returns empty utilization', function () {
    $result = (new ResourceUtilizationAnalyzer)->calculate(makeResourceData());

    expect($result['total_resources_analyzed'])->toBe(0)
        ->and($result['utilization'])->toBeEmpty()
        ->and($result['over_allocations'])->toBeEmpty();
});

test('assignment with missing task_id is skipped', function () {
    $data = makeResourceData(
        taskrsrc: [['rsrc_id' => 'R1', 'target_qty' => '8']], // no task_id
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    expect((new ResourceUtilizationAnalyzer)->calculate($data)['total_resources_analyzed'])->toBe(0);
});

test('assignment with missing rsrc_id is skipped', function () {
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-01', 'target_end_date' => '2024-01-05', 'target_drtn_hr_cnt' => '40']],
        taskrsrc: [['task_id' => 'T1', 'target_qty' => '8']], // no rsrc_id
    );

    expect((new ResourceUtilizationAnalyzer)->calculate($data)['total_resources_analyzed'])->toBe(0);
});

test('assignment where activity not found is skipped', function () {
    $data = makeResourceData(
        taskrsrc: [['task_id' => 'GHOST', 'rsrc_id' => 'R1', 'target_qty' => '8']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    expect((new ResourceUtilizationAnalyzer)->calculate($data)['total_resources_analyzed'])->toBe(0);
});

test('assignment where resource not found is skipped', function () {
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-01', 'target_end_date' => '2024-01-01', 'target_drtn_hr_cnt' => '8']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'GHOST', 'target_qty' => '8']],
        rsrc: [], // resource not in table
    );

    expect((new ResourceUtilizationAnalyzer)->calculate($data)['total_resources_analyzed'])->toBe(0);
});

// ---------------------------------------------------------------
// Basic utilization tracking
// ---------------------------------------------------------------

test('single day assignment creates one utilization period entry', function () {
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-01 00:00:00', 'target_end_date' => '2024-01-01 00:00:00', 'target_drtn_hr_cnt' => '8']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '8']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day');

    expect($result['total_resources_analyzed'])->toBe(1)
        ->and($result['utilization'])->toHaveKey('John')
        ->and(count($result['utilization']['John']))->toBe(1);
});

test('total_resources_analyzed equals number of unique resources with assignments', function () {
    $data = makeResourceData(
        tasks: [
            ['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-01 00:00:00', 'target_end_date' => '2024-01-01 00:00:00', 'target_drtn_hr_cnt' => '8'],
        ],
        taskrsrc: [
            ['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '8'],
            ['task_id' => 'T1', 'rsrc_id' => 'R2', 'target_qty' => '4'],
        ],
        rsrc: [
            ['rsrc_id' => 'R1', 'rsrc_name' => 'John'],
            ['rsrc_id' => 'R2', 'rsrc_name' => 'Jane'],
        ],
    );

    expect((new ResourceUtilizationAnalyzer)->calculate($data)['total_resources_analyzed'])->toBe(2);
});

// ---------------------------------------------------------------
// Over-allocation detection
// ---------------------------------------------------------------

test('qty_per_day above daily limit creates over_allocation entry', function () {
    // 1 day, 16 hrs, limit 8 → over-allocated
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'task_name' => 'Build', 'target_start_date' => '2024-01-01 00:00:00', 'target_end_date' => '2024-01-01 00:00:00', 'target_drtn_hr_cnt' => '16']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '16']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day', 8.0);

    expect($result['over_allocations'])->not->toBeEmpty()
        ->and($result['over_allocations'][0]['resource'])->toBe('John')
        ->and($result['over_allocations'][0]['qty_per_day'])->toBe(16.0);
});

test('qty_per_day exactly at daily limit does not trigger over_allocation', function () {
    // 1 day, 8 hrs, limit 8 → NOT over-allocated (strictly greater)
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-01 00:00:00', 'target_end_date' => '2024-01-01 00:00:00', 'target_drtn_hr_cnt' => '8']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '8']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day', 8.0);

    expect($result['over_allocations'])->toBeEmpty();
});

test('over_allocation entry has required keys', function () {
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'task_name' => 'Demo', 'target_start_date' => '2024-06-01 00:00:00', 'target_end_date' => '2024-06-01 00:00:00', 'target_drtn_hr_cnt' => '16']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '16']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $entry = (new ResourceUtilizationAnalyzer)->calculate($data, 'day', 8.0)['over_allocations'][0];

    expect($entry)->toHaveKeys(['resource', 'date', 'activity', 'qty_per_day']);
});

// ---------------------------------------------------------------
// Period grouping
// ---------------------------------------------------------------

test('day period produces daily keys', function () {
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-01 00:00:00', 'target_end_date' => '2024-01-03 00:00:00', 'target_drtn_hr_cnt' => '24']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '24']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day');

    // 3 days: 2024-01-01, 2024-01-02, 2024-01-03
    expect(count($result['utilization']['John']))->toBe(3)
        ->and($result['utilization']['John'])->toHaveKey('2024-01-01');
});

test('month period groups by YYYY-MM', function () {
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-15 00:00:00', 'target_end_date' => '2024-01-20 00:00:00', 'target_drtn_hr_cnt' => '48']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '48']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'month');

    expect($result['utilization']['John'])->toHaveKey('2024-01');
});

test('week period groups with (Week) suffix', function () {
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-15 00:00:00', 'target_end_date' => '2024-01-15 00:00:00', 'target_drtn_hr_cnt' => '8']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '8']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $keys = array_keys((new ResourceUtilizationAnalyzer)->calculate($data, 'week')['utilization']['John']);

    expect($keys[0])->toContain('(Week)');
});

test('activity with start after end is skipped', function () {
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-10 00:00:00', 'target_end_date' => '2024-01-01 00:00:00', 'target_drtn_hr_cnt' => '8']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '8']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    expect((new ResourceUtilizationAnalyzer)->calculate($data)['total_resources_analyzed'])->toBe(0);
});

test('runs against real XER fixture without error', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'week');

    expect($result)->toHaveKeys(['utilization', 'over_allocations', 'total_resources_analyzed']);
});

// ---------------------------------------------------------------
// Break/Continue Mutation Paths (kills ContinueToBreak lines 33, 40, 50)
// ---------------------------------------------------------------

test('invalid assignment continues to next assignment rather than breaking loop', function () {
    $data = makeResourceData(
        tasks: [
            ['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-01 00:00:00', 'target_end_date' => '2024-01-01 00:00:00', 'target_drtn_hr_cnt' => '8'],
            ['task_id' => 'T2', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-02 00:00:00', 'target_end_date' => '2024-01-02 00:00:00', 'target_drtn_hr_cnt' => '8'],
        ],
        taskrsrc: [
            ['task_id' => 'T1', 'rsrc_id' => 'GHOST', 'target_qty' => '8'], // Invalid resource -> continue
            ['task_id' => 'T2', 'rsrc_id' => 'R1', 'target_qty' => '8'],    // Valid assignment -> must be processed
        ],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day');

    // If ContinueToBreak occurred, John would not be analyzed
    expect($result['total_resources_analyzed'])->toBe(1)
        ->and($result['utilization'])->toHaveKey('John');
});

test('assignment with missing start/end date continues rather than breaking loop', function () {
    $data = makeResourceData(
        tasks: [
            ['task_id' => 'T1', 'status_code' => 'TK_Active'], // No dates -> continue line 50
            ['task_id' => 'T2', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-02 00:00:00', 'target_end_date' => '2024-01-02 00:00:00'],
        ],
        taskrsrc: [
            ['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '8'],
            ['task_id' => 'T2', 'rsrc_id' => 'R1', 'target_qty' => '8'],
        ],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day');

    expect($result['utilization']['John'])->toHaveKey('2024-01-02');
});

test('BooleanOrToBooleanAnd on date checks is killed by null end date', function () {
    $data = makeResourceData(
        tasks: [
            ['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-01 00:00:00'], // start valid, end null
        ],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '8']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day');

    // With `! $start || ! $end`, it continues. If mutated to `&&`, it wouldn't continue and would crash on null end.
    expect($result['total_resources_analyzed'])->toBe(0);
});

// ---------------------------------------------------------------
// Calculations (kills PlusEqualToMinusEqual, DivisionToMultiplication)
// ---------------------------------------------------------------

test('qty_per_day divides qty by days and adds to total utilization', function () {
    // 20 hours over 2 days -> 10 per day
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-01 00:00:00', 'target_end_date' => '2024-01-02 00:00:00']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '20']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day');

    // DivisionToMultiplication -> 20 * 2 = 40 (fails)
    // PlusEqualToMinusEqual -> 0.0 -= 10 -> -10 (fails)
    // DecrementFloat/IncrementFloat on 0.0 -> starts at -1.0 or 1.0 (fails)
    expect($result['utilization']['John']['2024-01-01'])->toBe(10.0);
});

// ---------------------------------------------------------------
// Edge Cases & Defaults (kills null fallbacks and precision)
// ---------------------------------------------------------------

test('assignment with no target_qty defaults to 0', function () {
    // Kills DecrementInteger: target_qty ?? 0 -> ?? -1
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-01 00:00:00', 'target_end_date' => '2024-01-01 00:00:00']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1']], // No qty
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day');

    expect($result['utilization']['John']['2024-01-01'])->toBe(0.0);
});

test('over_allocation qty_per_day is rounded to exactly 2 decimal places', function () {
    // 10 hours over 3 days -> 3.333333... -> 3.33
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'task_name' => 'A1', 'target_start_date' => '2024-01-01 00:00:00', 'target_end_date' => '2024-01-03 00:00:00']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '10']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    // Set daily limit to 2 to trigger overallocation
    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day', 2.0);

    // Kills DecrementInteger/IncrementInteger on round(..., 2)
    expect($result['over_allocations'][0]['qty_per_day'])->toBe(3.33);
});

test('week period key concatenation format is correct', function () {
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-17 00:00:00', 'target_end_date' => '2024-01-17 00:00:00']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '8']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'week');
    $keys = array_keys($result['utilization']['John']);

    // Kills ConcatRemoveLeft / ConcatSwitchSides on ' (Week)'
    expect($keys[0])->toBe('2024-01-15 (Week)');
});

// ---------------------------------------------------------------
// Config value (kills dailyLimit defaults and config fallback)
// ---------------------------------------------------------------

test('daily limit uses config value if present and no override provided', function () {
    // Requires illuminate/support Config facade, which should be present in Pest Laravel testing
    if (class_exists(Config::class)) {
        Config::set('p6-analyzer.resource_overallocation_daily_limit', 4.0);
    }

    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'task_name' => 'A1', 'target_start_date' => '2024-01-01 00:00:00', 'target_end_date' => '2024-01-01 00:00:00']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '5']], // 5 > 4 (config), but < 8 (default)
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    // No daily limit provided to calculate()
    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day');

    if (class_exists(Config::class)) {
        // If config was used, it's over allocated (5 > 4)
        expect($result['over_allocations'])->not->toBeEmpty();
    } else {
        // If config wasn't used, fallback is 8, so 5 is not over allocated
        expect($result['over_allocations'])->toBeEmpty();
    }
});

// ---------------------------------------------------------------
// v2.1.1 — whole-day allocation and assignment-date preference
// ---------------------------------------------------------------

test('distributes the full quantity when dates carry times (Carbon 3 fractional diff)', function () {
    $data = new ParsedXerData(
        header: '',
        tables: collect([
            'TASK' => collect([[
                'task_id' => '1', 'task_code' => 'A1', 'task_name' => 'T',
                'status_code' => 'TK_Active',
                'target_start_date' => '2024-06-14 08:00', 'target_end_date' => '2024-06-20 17:00',
            ]]),
            'TASKRSRC' => collect([[
                'task_id' => '1', 'rsrc_id' => 'R1', 'target_qty' => '70',
            ]]),
            'RSRC' => collect([[
                'rsrc_id' => 'R1', 'rsrc_name' => 'Welder',
            ]]),
            'TASKPRED' => collect([]),
            'PROJWBS' => collect([]),
        ])
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'month');

    // 7 whole days at 10h/day — every hour lands, none silently vanish.
    expect(round(array_sum($result['utilization']['Welder']), 2))->toEqual(70.0);
});

test('prefers assignment dates over activity dates when present', function () {
    $data = new ParsedXerData(
        header: '',
        tables: collect([
            'TASK' => collect([[
                'task_id' => '1', 'task_code' => 'A1', 'task_name' => 'T',
                'status_code' => 'TK_Active',
                'target_start_date' => '2024-03-01', 'target_end_date' => '2024-03-31',
            ]]),
            'TASKRSRC' => collect([[
                'task_id' => '1', 'rsrc_id' => 'R1', 'target_qty' => '40',
                // The resource works only one week of the month-long activity.
                'target_start_date' => '2024-03-11', 'target_end_date' => '2024-03-15',
            ]]),
            'RSRC' => collect([[
                'rsrc_id' => 'R1', 'rsrc_name' => 'Welder',
            ]]),
            'TASKPRED' => collect([]),
            'PROJWBS' => collect([]),
        ])
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day');

    // 5 days at 8h/day, only within the assignment window.
    expect($result['utilization']['Welder'])->toHaveCount(5)
        ->and($result['utilization']['Welder']['2024-03-11'])->toEqual(8.0)
        ->and(isset($result['utilization']['Welder']['2024-03-01']))->toBeFalse();
});
