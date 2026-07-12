<?php

use TeckFuture\P6Analyzer\Analyzers\CriticalPathCalculator;
use TeckFuture\P6Analyzer\IO\XerParser;

test('it calculates critical path from real XER data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new CriticalPathCalculator)->calculate($data->getActivities());

    expect($result)->toHaveKeys(['total_activities', 'critical_activities_count', 'critical_path_percentage', 'critical_activities'])
        ->and($result['total_activities'])->toBeGreaterThan(0);
});

test('it uses custom threshold', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $strict = (new CriticalPathCalculator)->calculate($data->getActivities(), 0);
    $relaxed = (new CriticalPathCalculator)->calculate($data->getActivities(), 160);

    expect($relaxed['critical_activities_count'])->toBeGreaterThanOrEqual($strict['critical_activities_count']);
});

test('it handles empty collection', function () {
    $result = (new CriticalPathCalculator)->calculate(collect());

    expect($result['total_activities'])->toBe(0)
        ->and($result['critical_activities_count'])->toBe(0)
        ->and($result['critical_path_percentage'])->toBe(0);
});
