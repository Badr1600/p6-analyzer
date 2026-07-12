<?php

use TeckFuture\P6Analyzer\Analyzers\ProgressSummarizer;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\IO\XerParser;

test('it summarizes progress from real data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new ProgressSummarizer)->summarize($data);

    expect($result)->toHaveKeys([
        'total_activities',
        'status_counts',
        'status_percentages',
        'overall_percent_complete_by_duration',
    ])
        ->and($result['status_counts'])->toHaveKeys(['not_started', 'in_progress', 'completed']);
});

test('status counts add up to total activities', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new ProgressSummarizer)->summarize($data);

    $sum = $result['status_counts']['not_started']
        + $result['status_counts']['in_progress']
        + $result['status_counts']['completed'];

    expect($sum)->toBe($result['total_activities']);
});

test('status percentages add up to approximately 100', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new ProgressSummarizer)->summarize($data);

    $total = $result['status_percentages']['not_started']
        + $result['status_percentages']['in_progress']
        + $result['status_percentages']['completed'];

    // Allow floating-point rounding tolerance
    expect($total)->toBeGreaterThanOrEqual(99.9)->toBeLessThanOrEqual(100.1);
});

test('overall_percent_complete_by_duration is between 0 and 100', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new ProgressSummarizer)->summarize($data);

    expect($result['overall_percent_complete_by_duration'])
        ->toBeGreaterThanOrEqual(0)
        ->toBeLessThanOrEqual(100);
});

test('handles empty collection', function () {
    // Build minimal ParsedXerData with no activities
    $emptyData = new ParsedXerData(header: '', tables: collect());
    $result = (new ProgressSummarizer)->summarize($emptyData);

    expect($result['total_activities'])->toBe(0)
        ->and($result['overall_percent_complete_by_duration'])->toEqual(0);
});

test('all-completed schedule has 100 percent complete', function () {
    $activities = collect([
        ActivityData::fromModelOrArray([
            'task_id' => '1',
            'status_code' => 'TK_Complete',
            'target_drtn_hr_cnt' => '80',
            'phys_comp_pct' => '100',
        ]),
        ActivityData::fromModelOrArray([
            'task_id' => '2',
            'status_code' => 'TK_Complete',
            'target_drtn_hr_cnt' => '40',
            'phys_comp_pct' => '100',
        ]),
    ]);

    $data = new ParsedXerData(header: '', tables: collect([
        'TASK' => collect([
            ['task_id' => '1', 'status_code' => 'TK_Complete', 'target_drtn_hr_cnt' => '80', 'phys_comp_pct' => '100'],
            ['task_id' => '2', 'status_code' => 'TK_Complete', 'target_drtn_hr_cnt' => '40', 'phys_comp_pct' => '100'],
        ]),
    ]));

    $result = (new ProgressSummarizer)->summarize($data);

    expect($result['status_counts']['completed'])->toBe(2)
        ->and($result['status_counts']['not_started'])->toBe(0)
        ->and($result['overall_percent_complete_by_duration'])->toBe(100.0);
});
