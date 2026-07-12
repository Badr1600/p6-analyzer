<?php

use TeckFuture\P6Analyzer\Analyzers\ResourceUtilizationAnalyzer;
use TeckFuture\P6Analyzer\IO\XerParser;

test('it calculates resource utilization from real XER data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new ResourceUtilizationAnalyzer)->calculate($data);

    expect($result)->toHaveKeys(['utilization', 'over_allocations', 'total_resources_analyzed']);
});

test('it supports different period groupings', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());

    $daily = (new ResourceUtilizationAnalyzer)->calculate($data, 'day');
    $weekly = (new ResourceUtilizationAnalyzer)->calculate($data, 'week');
    $monthly = (new ResourceUtilizationAnalyzer)->calculate($data, 'month');

    expect($daily)->toHaveKey('utilization')
        ->and($weekly)->toHaveKey('utilization')
        ->and($monthly)->toHaveKey('utilization');
});
