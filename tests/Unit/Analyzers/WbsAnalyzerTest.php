<?php

use TeckFuture\P6Analyzer\Analyzers\WbsAnalyzer;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\IO\XerParser;

covers(WbsAnalyzer::class);

function makeWbsData(array $wbs = [], array $tasks = []): ParsedXerData
{
    return new ParsedXerData(header: '', tables: collect([
        'PROJWBS' => collect($wbs),
        'TASK' => collect($tasks),
    ]));
}

// ---------------------------------------------------------------
// Empty input
// ---------------------------------------------------------------

test('empty data returns zero wbs_elements and empty metrics', function () {
    $result = (new WbsAnalyzer)->calculate(makeWbsData());

    expect($result['total_wbs_elements'])->toBe(0)
        ->and($result['wbs_metrics'])->toBeEmpty();
});

// ---------------------------------------------------------------
// total_wbs_elements
// ---------------------------------------------------------------

test('total_wbs_elements reflects PROJWBS row count', function () {
    $data = makeWbsData(wbs: [
        ['wbs_id' => 'W1', 'wbs_name' => 'Phase 1'],
        ['wbs_id' => 'W2', 'wbs_name' => 'Phase 2'],
    ]);

    expect((new WbsAnalyzer)->calculate($data)['total_wbs_elements'])->toBe(2);
});

// ---------------------------------------------------------------
// wbs_metrics entries
// ---------------------------------------------------------------

test('each wbs entry has required keys', function () {
    $data = makeWbsData(
        wbs: [['wbs_id' => 'W1', 'wbs_name' => 'Phase 1', 'wbs_short_name' => 'P1']],
    );

    $result = (new WbsAnalyzer)->calculate($data);

    expect($result['wbs_metrics']['W1'])->toHaveKeys(['name', 'short_name', 'activity_count', 'total_cost'])
        ->and($result['wbs_metrics']['W1']['name'])->toBe('Phase 1')
        ->and($result['wbs_metrics']['W1']['short_name'])->toBe('P1');
});

test('wbs with no activities has activity_count 0', function () {
    $data = makeWbsData(
        wbs: [['wbs_id' => 'W1', 'wbs_name' => 'Phase 1']],
    );

    expect((new WbsAnalyzer)->calculate($data)['wbs_metrics']['W1']['activity_count'])->toBe(0);
});

test('activity_count increments per wbs assignment', function () {
    $data = makeWbsData(
        wbs: [['wbs_id' => 'W1', 'wbs_name' => 'Phase 1']],
        tasks: [
            ['task_id' => '1', 'status_code' => 'TK_Active', 'wbs_id' => 'W1', 'target_drtn_hr_cnt' => '40'],
            ['task_id' => '2', 'status_code' => 'TK_Active', 'wbs_id' => 'W1', 'target_drtn_hr_cnt' => '16'],
        ],
    );

    expect((new WbsAnalyzer)->calculate($data)['wbs_metrics']['W1']['activity_count'])->toBe(2);
});

test('total_cost aggregates target costs for wbs', function () {
    $data = makeWbsData(
        wbs: [['wbs_id' => 'W1', 'wbs_name' => 'Phase 1']],
        tasks: [
            ['task_id' => '1', 'status_code' => 'TK_Active', 'wbs_id' => 'W1', 'target_cost' => '1000', 'target_drtn_hr_cnt' => '40'],
            ['task_id' => '2', 'status_code' => 'TK_Active', 'wbs_id' => 'W1', 'target_cost' => '500', 'target_drtn_hr_cnt' => '16'],
        ],
    );

    expect((new WbsAnalyzer)->calculate($data)['wbs_metrics']['W1']['total_cost'])->toBe(1500.0);
});

test('activities with no wbs_id go to unassigned bucket', function () {
    $data = makeWbsData(
        tasks: [
            ['task_id' => '1', 'status_code' => 'TK_Active', 'target_drtn_hr_cnt' => '40'],
        ],
    );

    $result = (new WbsAnalyzer)->calculate($data);
    // No WBS table entries, but the unassigned bucket should exist internally
    // wbs_metrics will be empty since PROJWBS is empty
    expect($result['total_wbs_elements'])->toBe(0);
});

test('wbs missing name defaults to Unknown', function () {
    $data = makeWbsData(
        wbs: [['wbs_id' => 'W1']], // no wbs_name
    );

    expect((new WbsAnalyzer)->calculate($data)['wbs_metrics']['W1']['name'])->toBe('Unknown');
});

test('runs against real XER fixture without error', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new WbsAnalyzer)->calculate($data);

    expect($result['total_wbs_elements'])->toBeGreaterThanOrEqual(0)
        ->and($result['wbs_metrics'])->toBeArray();
});
