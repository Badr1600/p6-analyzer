<?php

use TeckFuture\P6Analyzer\Analyzers\WorkPackageSummarizer;
use TeckFuture\P6Analyzer\IO\XerParser;

test('it summarizes work packages from real data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new WorkPackageSummarizer)->summarize($data);

    expect($result)->toBeArray();

    if (count($result) > 0) {
        expect($result[0])->toHaveKeys([
            'name',
            'activity_count',
            'total_duration_hr',
            'total_cost',
            'completed_activities',
            'percent_complete_by_count',
        ]);
    }
});

test('percent_complete_by_count is between 0 and 100', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new WorkPackageSummarizer)->summarize($data);

    foreach ($result as $package) {
        expect($package['percent_complete_by_count'])
            ->toBeGreaterThanOrEqual(0)
            ->toBeLessThanOrEqual(100);
    }
});

test('total counts add up to total activities', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new WorkPackageSummarizer)->summarize($data);

    $totalFromPackages = array_sum(array_column($result, 'activity_count'));
    $totalActivities = $data->getActivities()->count();

    expect($totalFromPackages)->toBe($totalActivities);
});
