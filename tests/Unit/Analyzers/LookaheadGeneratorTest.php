<?php

use TeckFuture\P6Analyzer\Analyzers\LookaheadGenerator;
use TeckFuture\P6Analyzer\Analyzers\ProgressSummarizer;
use TeckFuture\P6Analyzer\Analyzers\WbsAnalyzer;
use TeckFuture\P6Analyzer\Analyzers\WorkPackageSummarizer;
use TeckFuture\P6Analyzer\IO\XerParser;

test('LookaheadGenerator generates lookahead from real data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new LookaheadGenerator)->generate($data, '2024-01-01', 3);

    expect($result)->toHaveKeys(['data_date', 'lookahead_weeks', 'end_date', 'activity_count', 'activities'])
        ->and($result['lookahead_weeks'])->toBe(3);
});

test('WbsAnalyzer calculates WBS metrics from real data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new WbsAnalyzer)->calculate($data);

    expect($result)->toHaveKeys(['total_wbs_elements', 'wbs_metrics']);
});

test('WorkPackageSummarizer summarizes work packages from real data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new WorkPackageSummarizer)->summarize($data);

    expect($result)->toBeArray();
    if (count($result) > 0) {
        expect($result[0])->toHaveKeys(['name', 'activity_count', 'total_duration_hr', 'total_cost', 'percent_complete_by_count']);
    }
});

test('ProgressSummarizer summarizes progress from real data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new ProgressSummarizer)->summarize($data);

    expect($result)->toHaveKeys(['total_activities', 'status_counts', 'status_percentages', 'overall_percent_complete_by_duration'])
        ->and($result['status_counts'])->toHaveKeys(['not_started', 'in_progress', 'completed']);
});
