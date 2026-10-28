<?php

use TeckFuture\P6Analyzer\Analyzers\ProgressSummarizer;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\IO\XerParser;

covers(ProgressSummarizer::class);

function makeProgressData(array $tasks = []): ParsedXerData
{
    return new ParsedXerData(header: '', tables: collect(['TASK' => collect($tasks)]));
}

function makeProgressTask(string $id, string $status, float $duration = 40.0, float $pct = 0.0): array
{
    return [
        'task_id' => $id,
        'status_code' => $status,
        'target_drtn_hr_cnt' => (string) $duration,
        'phys_comp_pct' => (string) $pct,
    ];
}

// ---------------------------------------------------------------
// Empty input
// ---------------------------------------------------------------

test('empty data returns all zeros', function () {
    $result = (new ProgressSummarizer)->summarize(makeProgressData());

    expect($result['total_activities'])->toBe(0)
        ->and($result['status_counts']['not_started'])->toBe(0)
        ->and($result['status_counts']['in_progress'])->toBe(0)
        ->and($result['status_counts']['completed'])->toBe(0)
        // percentages are 0, not a division by zero, when there are no activities
        ->and($result['status_percentages']['not_started'])->toBe(0)
        ->and($result['status_percentages']['in_progress'])->toBe(0)
        ->and($result['status_percentages']['completed'])->toBe(0)
        ->and($result['overall_percent_complete_by_duration'])->toEqual(0);
});

// ---------------------------------------------------------------
// Status classification
// ---------------------------------------------------------------

test('TK_Complete is counted as completed', function () {
    $result = (new ProgressSummarizer)->summarize(makeProgressData(tasks: [
        makeProgressTask('1', 'TK_Complete', pct: 100),
    ]));

    expect($result['status_counts']['completed'])->toBe(1)
        ->and($result['status_counts']['in_progress'])->toBe(0)
        ->and($result['status_counts']['not_started'])->toBe(0);
});

test('TK_Active is counted as in_progress', function () {
    $result = (new ProgressSummarizer)->summarize(makeProgressData(tasks: [
        makeProgressTask('1', 'TK_Active'),
    ]));

    expect($result['status_counts']['in_progress'])->toBe(1)
        ->and($result['status_counts']['not_started'])->toBe(0);
});

test('TK_NotStart is counted as not_started', function () {
    $result = (new ProgressSummarizer)->summarize(makeProgressData(tasks: [
        makeProgressTask('1', 'TK_NotStart'),
    ]));

    expect($result['status_counts']['not_started'])->toBe(1)
        ->and($result['status_counts']['in_progress'])->toBe(0);
});

test('status counts add up to total_activities', function () {
    $result = (new ProgressSummarizer)->summarize(makeProgressData(tasks: [
        makeProgressTask('1', 'TK_Complete', pct: 100),
        makeProgressTask('2', 'TK_Active'),
        makeProgressTask('3', 'TK_NotStart'),
        makeProgressTask('4', 'TK_NotStart'),
    ]));

    $sum = $result['status_counts']['not_started']
        + $result['status_counts']['in_progress']
        + $result['status_counts']['completed'];

    expect($sum)->toBe(4)
        ->and($result['total_activities'])->toBe(4);
});

// ---------------------------------------------------------------
// overall_percent_complete_by_duration = weighted average
// ---------------------------------------------------------------

test('100% complete activity contributes full duration to earned', function () {
    // 1 activity, 40hr, 100% done → overall = 100%
    $result = (new ProgressSummarizer)->summarize(makeProgressData(tasks: [
        makeProgressTask('1', 'TK_Complete', duration: 40.0, pct: 100.0),
    ]));

    expect($result['overall_percent_complete_by_duration'])->toBe(100.0);
});

test('50% complete activity contributes half duration to earned', function () {
    // 1 activity, 40hr, 50% done → earned = 20, total = 40 → overall = 50%
    $result = (new ProgressSummarizer)->summarize(makeProgressData(tasks: [
        makeProgressTask('1', 'TK_Active', duration: 40.0, pct: 50.0),
    ]));

    expect($result['overall_percent_complete_by_duration'])->toBe(50.0);
});

test('overall_percent_complete weights by duration', function () {
    // Activity 1: 40hr @ 100% → earned=40
    // Activity 2: 80hr @ 0%   → earned=0
    // Total=120, Earned=40 → 33.33%
    $result = (new ProgressSummarizer)->summarize(makeProgressData(tasks: [
        makeProgressTask('1', 'TK_Complete', duration: 40.0, pct: 100.0),
        makeProgressTask('2', 'TK_NotStart', duration: 80.0, pct: 0.0),
    ]));

    expect($result['overall_percent_complete_by_duration'])->toBe(33.33);
});

test('zero duration activities do not cause divide by zero', function () {
    $result = (new ProgressSummarizer)->summarize(makeProgressData(tasks: [
        makeProgressTask('1', 'TK_Active', duration: 0.0, pct: 50.0),
    ]));

    expect($result['overall_percent_complete_by_duration'])->toEqual(0);
});

// ---------------------------------------------------------------
// Status percentages
// ---------------------------------------------------------------

test('status_percentages add up to 100 for balanced distribution', function () {
    $result = (new ProgressSummarizer)->summarize(makeProgressData(tasks: [
        makeProgressTask('1', 'TK_Complete'),
        makeProgressTask('2', 'TK_Active'),
        makeProgressTask('3', 'TK_NotStart'),
        makeProgressTask('4', 'TK_NotStart'),
    ]));

    $sum = $result['status_percentages']['not_started']
        + $result['status_percentages']['in_progress']
        + $result['status_percentages']['completed'];

    expect($sum)->toBeGreaterThanOrEqual(99.9)->toBeLessThanOrEqual(100.1);
});

test('not_started percentage rounds to 2dp for fractional distribution', function () {
    // 2 of 3 not_started = 66.67%
    $result = (new ProgressSummarizer)->summarize(makeProgressData(tasks: [
        makeProgressTask('1', 'TK_NotStart'),
        makeProgressTask('2', 'TK_NotStart'),
        makeProgressTask('3', 'TK_Complete', pct: 100),
    ]));

    expect($result['status_percentages']['not_started'])->toBe(66.67)
        ->and($result['status_percentages']['completed'])->toBe(33.33);
});

test('in_progress percentage rounds to 2dp for fractional distribution', function () {
    // 1 of 3 in_progress = 33.33%
    $result = (new ProgressSummarizer)->summarize(makeProgressData(tasks: [
        makeProgressTask('1', 'TK_Active'),
        makeProgressTask('2', 'TK_NotStart'),
        makeProgressTask('3', 'TK_NotStart'),
    ]));

    expect($result['status_percentages']['in_progress'])->toBe(33.33)
        ->and($result['status_percentages']['not_started'])->toBe(66.67);
});

test('completed percentage is 14.29 for 1 of 7', function () {
    // 1/7 * 100 = 14.285... → 14.29 at 2dp (not 14.3 at 1dp or 14.286 at 3dp)
    $tasks = array_map(fn ($i) => makeProgressTask((string) $i, 'TK_NotStart'), range(1, 6));
    $tasks[] = makeProgressTask('7', 'TK_Complete', pct: 100);

    $result = (new ProgressSummarizer)->summarize(makeProgressData(tasks: $tasks));

    expect($result['status_percentages']['completed'])->toBe(14.29)
        ->and($result['status_percentages']['not_started'])->toBe(85.71);
});

test('100% completion when all completed', function () {
    $result = (new ProgressSummarizer)->summarize(makeProgressData(tasks: [
        makeProgressTask('1', 'TK_Complete', pct: 100),
        makeProgressTask('2', 'TK_Complete', pct: 100),
    ]));

    expect($result['status_percentages']['completed'])->toBe(100.0)
        ->and($result['status_percentages']['not_started'])->toBe(0.0);
});

test('runs against real XER fixture without error', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new ProgressSummarizer)->summarize($data);

    $sum = $result['status_counts']['not_started']
        + $result['status_counts']['in_progress']
        + $result['status_counts']['completed'];

    expect($sum)->toBe($result['total_activities'])
        ->and($result['overall_percent_complete_by_duration'])->toBe(3.24);
});

// ---------------------------------------------------------------
// A missing duration counts as zero
// ---------------------------------------------------------------

test('activity with no target_drtn_hr_cnt contributes 0 to total duration', function () {
    // Activity without a duration
    $data = makeProgressData(tasks: [[
        'task_id' => '1',
        'status_code' => 'TK_Active',
        'phys_comp_pct' => '50',
        // No target_drtn_hr_cnt — null path → 0
    ]]);

    $result = (new ProgressSummarizer)->summarize($data);

    // With no total duration the overall percentage is 0
    expect($result['overall_percent_complete_by_duration'])->toEqual(0);
});

// ---------------------------------------------------------------
// phys_comp_pct falls back to complete_pct
// ---------------------------------------------------------------

test('complete_pct is used when phys_comp_pct is absent', function () {
    $data = makeProgressData(tasks: [[
        'task_id' => '1',
        'status_code' => 'TK_Active',
        'target_drtn_hr_cnt' => '100',
        'complete_pct' => '50', // phys_comp_pct absent → falls to complete_pct
    ]]);

    $result = (new ProgressSummarizer)->summarize($data);

    // EV = 100hrs * 50% = 50, overall = 50/100 * 100 = 50%
    expect($result['overall_percent_complete_by_duration'])->toBe(50.0);
});

test('phys_comp_pct takes precedence over complete_pct when both present', function () {
    $data = makeProgressData(tasks: [[
        'task_id' => '1',
        'status_code' => 'TK_Active',
        'target_drtn_hr_cnt' => '100',
        'phys_comp_pct' => '75',
        'complete_pct' => '25',  // phys wins
    ]]);

    $result = (new ProgressSummarizer)->summarize($data);

    // phys=75% wins → completed duration = 75hrs
    expect($result['overall_percent_complete_by_duration'])->toBe(75.0);
});

// ---------------------------------------------------------------
// Smallest positive total duration
// ---------------------------------------------------------------

test('overall_pct_by_duration computes when total_duration is exactly 1hr', function () {
    // Even a total of one hour yields a real percentage rather than 0
    $data = makeProgressData(tasks: [[
        'task_id' => '1',
        'status_code' => 'TK_Active',
        'target_drtn_hr_cnt' => '1',  // exactly 1 hour
        'phys_comp_pct' => '100',
    ]]);

    $result = (new ProgressSummarizer)->summarize($data);

    // overall = 1/1 * 100 = 100
    expect($result['overall_percent_complete_by_duration'])->toBe(100.0);
});

// ---------------------------------------------------------------
// overall_pct_by_duration precision
// ---------------------------------------------------------------

test('overall_pct_by_duration rounds to 2 decimal places', function () {
    // 1/3 duration completed → 33.333... → 33.33 at 2dp (not 33.333 at 3dp)
    $data = makeProgressData(tasks: [[
        'task_id' => '1',
        'status_code' => 'TK_Active',
        'target_drtn_hr_cnt' => '300',
        'phys_comp_pct' => '100',  // 300hrs * 100% = 300 earned
    ], [
        'task_id' => '2',
        'status_code' => 'TK_NotStart',
        'target_drtn_hr_cnt' => '600',
        'phys_comp_pct' => '0',  // 0 earned
    ]]);

    // Earned = 300, Total = 900 → 300/900 * 100 = 33.333... → 33.33
    $result = (new ProgressSummarizer)->summarize($data);

    expect($result['overall_percent_complete_by_duration'])->toBe(33.33);
});
