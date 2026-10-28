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

test('week period keys and hours follow weekStartsOn', function (?int $weekStartsOn, array $expected) {
    // Friday 2026-10-02 to Monday 2026-10-05, 32 h total (8 h a day across the 4 calendar days).
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2026-10-02 00:00:00', 'target_end_date' => '2026-10-05 00:00:00', 'target_drtn_hr_cnt' => '32']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '32']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $utilization = (new ResourceUtilizationAnalyzer)->calculate($data, 'week', weekStartsOn: $weekStartsOn)['utilization']['John'];

    expect($utilization)->toBe($expected)
        ->and(array_sum($utilization))->toBe(32.0);
})->with([
    'Saturday (6)' => [6, ['2026-09-26 (Week)' => 8.0, '2026-10-03 (Week)' => 24.0]],
    'Sunday (0)' => [0, ['2026-09-27 (Week)' => 16.0, '2026-10-04 (Week)' => 16.0]],
    'Monday (1)' => [1, ['2026-09-28 (Week)' => 24.0, '2026-10-05 (Week)' => 8.0]],
    'null (locale default, Monday under en)' => [null, ['2026-09-28 (Week)' => 24.0, '2026-10-05 (Week)' => 8.0]],
]);

test('weekStartsOn outside 0-6 throws', function (int $weekStartsOn) {
    (new ResourceUtilizationAnalyzer)->calculate(makeResourceData(), 'week', weekStartsOn: $weekStartsOn);
})->with([7, -1])->throws(InvalidArgumentException::class, 'weekStartsOn must be a day number from 0 (Sunday) to 6 (Saturday).');

test('weekStartsOn is ignored for the month period', function () {
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2026-10-02 00:00:00', 'target_end_date' => '2026-10-05 00:00:00', 'target_drtn_hr_cnt' => '32']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '32']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $withOption = (new ResourceUtilizationAnalyzer)->calculate($data, 'month', weekStartsOn: 6)['utilization'];
    $withoutOption = (new ResourceUtilizationAnalyzer)->calculate($data, 'month', weekStartsOn: null)['utilization'];

    expect($withOption)->toBe($withoutOption);
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
// Invalid assignments are skipped without stopping the run
// ---------------------------------------------------------------

test('invalid assignment continues to next assignment rather than breaking loop', function () {
    $data = makeResourceData(
        tasks: [
            ['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-01 00:00:00', 'target_end_date' => '2024-01-01 00:00:00', 'target_drtn_hr_cnt' => '8'],
            ['task_id' => 'T2', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-02 00:00:00', 'target_end_date' => '2024-01-02 00:00:00', 'target_drtn_hr_cnt' => '8'],
        ],
        taskrsrc: [
            ['task_id' => 'T1', 'rsrc_id' => 'GHOST', 'target_qty' => '8'], // Unknown resource -> skipped
            ['task_id' => 'T2', 'rsrc_id' => 'R1', 'target_qty' => '8'],    // Valid assignment -> must be processed
        ],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day');

    // The valid assignment after the invalid one must still be analyzed
    expect($result['total_resources_analyzed'])->toBe(1)
        ->and($result['utilization'])->toHaveKey('John');
});

test('assignment with missing start/end date continues rather than breaking loop', function () {
    $data = makeResourceData(
        tasks: [
            ['task_id' => 'T1', 'status_code' => 'TK_Active'], // No dates -> skipped
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

test('assignment with a start date but no end date is skipped', function () {
    $data = makeResourceData(
        tasks: [
            ['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-01 00:00:00'], // start valid, end null
        ],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '8']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day');

    // A missing end date skips the assignment instead of failing on the null date
    expect($result['total_resources_analyzed'])->toBe(0);
});

// ---------------------------------------------------------------
// Calculations
// ---------------------------------------------------------------

test('qty_per_day divides qty by days and adds to total utilization', function () {
    // 20 hours over 2 days -> 10 per day
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-01 00:00:00', 'target_end_date' => '2024-01-02 00:00:00']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '20']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day');

    expect($result['utilization']['John']['2024-01-01'])->toBe(10.0);
});

// ---------------------------------------------------------------
// Edge cases and defaults
// ---------------------------------------------------------------

test('assignment with no target_qty defaults to 0', function () {
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

    expect($result['over_allocations'][0]['qty_per_day'])->toBe(3.33);
});

test('week period key is the week start date followed by a (Week) suffix', function () {
    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-17 00:00:00', 'target_end_date' => '2024-01-17 00:00:00']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '8']],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'week');
    $keys = array_keys($result['utilization']['John']);

    expect($keys[0])->toBe('2024-01-15 (Week)');
});

// ---------------------------------------------------------------
// Config value
// ---------------------------------------------------------------

test('daily limit uses config value if present and no override provided', function () {
    Config::set('p6-analyzer.resource_overallocation_daily_limit', 4.0);

    $data = makeResourceData(
        tasks: [['task_id' => 'T1', 'status_code' => 'TK_Active', 'task_name' => 'A1', 'target_start_date' => '2024-01-01 00:00:00', 'target_end_date' => '2024-01-01 00:00:00']],
        taskrsrc: [['task_id' => 'T1', 'rsrc_id' => 'R1', 'target_qty' => '5']], // 5 > 4 (config), but < 8 (default)
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    // No daily limit provided to calculate()
    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day');

    // Over-allocated only because the configured limit (4) was applied; the built-in default of 8 would not flag 5
    expect($result['over_allocations'])->not->toBeEmpty();
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

// ---------------------------------------------------------------
// Role-only lines
// ---------------------------------------------------------------

function makeRoleData(array $taskrsrc, array $rsrc = [], ?array $roles = null): ParsedXerData
{
    $tables = [
        'TASK' => collect([['task_id' => 'T1', 'task_name' => 'Pour', 'status_code' => 'TK_Active', 'target_start_date' => '2024-01-01', 'target_end_date' => '2024-01-02', 'target_drtn_hr_cnt' => '16']]),
        'TASKRSRC' => collect($taskrsrc),
        'RSRC' => collect($rsrc),
    ];

    if ($roles !== null) {
        $tables['ROLES'] = collect($roles);
    }

    return new ParsedXerData(header: '', tables: collect($tables));
}

test('role-only line is bucketed by role using the provided label', function () {
    $data = makeRoleData(
        [['task_id' => 'T1', 'rsrc_id' => '', 'role_id' => '12', 'target_qty' => '16']],
        roles: [['role_id' => '12', 'role_name' => 'Planner', 'role_short_name' => 'PLN']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day', null, null, [12 => 'Lead planner']);

    expect($result['utilization'])->toBe(['role:12' => ['2024-01-01' => 8.0, '2024-01-02' => 8.0]])
        ->and($result['roles'])->toBe(['role:12' => ['kind' => 'role', 'role_id' => '12', 'label' => 'Lead planner']])
        ->and($result['total_resources_analyzed'])->toBe(1)
        ->and($result['over_allocations'])->toBeEmpty();
});

test('role label falls back to ROLES short name then Role id', function () {
    $data = makeRoleData(
        [
            ['task_id' => 'T1', 'rsrc_id' => '', 'role_id' => '12', 'target_qty' => '8'],
            ['task_id' => 'T1', 'rsrc_id' => '', 'role_id' => '13', 'target_qty' => '8'],
        ],
        roles: [['role_id' => '12', 'role_name' => 'Planner', 'role_short_name' => 'PLN']],
    );

    $roles = (new ResourceUtilizationAnalyzer)->calculate($data)['roles'];

    expect($roles['role:12']['label'])->toBe('PLN')
        ->and($roles['role:13']['label'])->toBe('Role 13');
});

test('role over-allocation carries the bucket key, kind and label', function () {
    $data = makeRoleData([['task_id' => 'T1', 'rsrc_id' => '', 'role_id' => '12', 'target_qty' => '40']]);

    $over = (new ResourceUtilizationAnalyzer)->calculate($data, 'day', 8.0, null, [12 => 'Planner'])['over_allocations'];

    expect($over[0])->toMatchArray(['resource' => 'role:12', 'kind' => 'role', 'label' => 'Planner', 'qty_per_day' => 20.0]);
});

test('mixed resource and role lines keep the resource shape unchanged', function () {
    $data = makeRoleData(
        [
            ['task_id' => 'T1', 'rsrc_id' => 'R1', 'role_id' => '12', 'target_qty' => '16'],
            ['task_id' => 'T1', 'rsrc_id' => '', 'role_id' => '12', 'target_qty' => '8'],
        ],
        rsrc: [['rsrc_id' => 'R1', 'rsrc_name' => 'John']],
    );

    $result = (new ResourceUtilizationAnalyzer)->calculate($data, 'day');

    expect($result['utilization']['John'])->toBe(['2024-01-01' => 8.0, '2024-01-02' => 8.0])
        ->and($result['utilization']['role:12'])->toBe(['2024-01-01' => 4.0, '2024-01-02' => 4.0])
        ->and(array_keys($result['roles']))->toBe(['role:12'])
        ->and($result['total_resources_analyzed'])->toBe(2);
});

test('role line with unknown activity or no dates is skipped', function () {
    $data = makeRoleData([['task_id' => 'GHOST', 'rsrc_id' => '', 'role_id' => '12', 'target_qty' => '8']]);

    expect((new ResourceUtilizationAnalyzer)->calculate($data)['roles'])->toBeEmpty();
});
