<?php

use TeckFuture\P6Analyzer\Analyzers\DCMA14PointChecker;
use TeckFuture\P6Analyzer\IO\XerParser;

test('it calculates DCMA score from real XER data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new DCMA14PointChecker)->calculate($data->getActivities());

    expect($result)->toHaveKeys(['total_activities', 'incomplete_activities', 'negative_float_count', 'overall_score'])
        ->and($result['overall_score'])->toBeGreaterThanOrEqual(0)
        ->and($result['overall_score'])->toBeLessThanOrEqual(100);
});

test('it accepts a data date for date validation', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new DCMA14PointChecker)->calculate($data->getActivities(), '2024-01-15');

    expect($result)->toHaveKey('invalid_dates_count');
});

test('it clamps score between 0 and 100', function () {
    $result = (new DCMA14PointChecker)->calculate(collect());

    expect($result['overall_score'])->toBeGreaterThanOrEqual(0)
        ->and($result['overall_score'])->toBeLessThanOrEqual(100);
});
