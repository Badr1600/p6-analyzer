<?php

use TeckFuture\P6Analyzer\Analyzers\RelationshipAnalyzer;
use TeckFuture\P6Analyzer\IO\XerParser;

test('it analyzes relationships from real XER data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new RelationshipAnalyzer)->calculate($data->getRelationships());

    expect($result)->toHaveKeys(['total_relationships', 'type_distribution', 'type_percentages', 'lag_distribution']);
});

test('it handles empty collection', function () {
    $result = (new RelationshipAnalyzer)->calculate(collect());

    expect($result['total_relationships'])->toBe(0);
});
