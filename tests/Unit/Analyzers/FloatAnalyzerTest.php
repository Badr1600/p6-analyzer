<?php

use TeckFuture\P6Analyzer\Analyzers\FloatAnalyzer;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\IO\XerParser;

test('it calculates float distribution from real XER data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new FloatAnalyzer)->calculate($data->getActivities());

    expect($result)->toHaveKeys(['total_activities', 'distribution'])
        ->and($result['distribution'])->toHaveKeys(['negative', 'zero_to_ten', 'eleven_to_thirty', 'thirty_plus', 'missing']);
});

test('it handles empty collection', function () {
    $result = (new FloatAnalyzer)->calculate(collect());
    expect($result['total_activities'])->toBe(0);
});

test('it counts all null floats as missing', function () {
    $activities = collect([
        ActivityData::fromModelOrArray(['task_id' => '1']),
        ActivityData::fromModelOrArray(['task_id' => '2']),
    ]);

    $result = (new FloatAnalyzer)->calculate($activities);

    expect($result['distribution']['missing'])->toBe(2);
});
