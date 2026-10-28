<?php

use TeckFuture\P6Analyzer\Analyzers\RelationshipAnalyzer;
use TeckFuture\P6Analyzer\DTOs\RelationshipData;
use TeckFuture\P6Analyzer\IO\XerParser;

covers(RelationshipAnalyzer::class);

function makeRel(string $type, float $lag = 0.0): RelationshipData
{
    return RelationshipData::fromModelOrArray([
        'task_pred_id' => uniqid(),
        'pred_type' => $type,
        'lag_hr_cnt' => (string) $lag,
    ]);
}

// ---------------------------------------------------------------
// Empty input
// ---------------------------------------------------------------

test('empty collection returns zeros', function () {
    $result = (new RelationshipAnalyzer)->calculate(collect());

    expect($result['total_relationships'])->toBe(0)
        ->and($result['type_distribution']['FS'])->toBe(0)
        ->and($result['type_distribution']['SS'])->toBe(0)
        ->and($result['type_distribution']['FF'])->toBe(0)
        ->and($result['type_distribution']['SF'])->toBe(0)
        ->and($result['type_distribution']['unknown'])->toBe(0)
        // percentages are 0, not a division by zero, when there are no relationships
        ->and($result['type_percentages']['FS'])->toBe(0)
        ->and($result['type_percentages']['SS'])->toBe(0)
        ->and($result['type_percentages']['FF'])->toBe(0)
        ->and($result['type_percentages']['SF'])->toBe(0)
        ->and($result['lag_distribution']['negative'])->toBe(0)
        ->and($result['lag_distribution']['zero'])->toBe(0)
        ->and($result['lag_distribution']['positive'])->toBe(0);
});

// ---------------------------------------------------------------
// Type distribution counting
// ---------------------------------------------------------------

test('FS relationships are counted correctly', function () {
    $relationships = collect([makeRel('PR_FS'), makeRel('PR_FS'), makeRel('PR_SS')]);
    $result = (new RelationshipAnalyzer)->calculate($relationships);

    expect($result['type_distribution']['FS'])->toBe(2)
        ->and($result['type_distribution']['SS'])->toBe(1)
        ->and($result['total_relationships'])->toBe(3);
});

test('SS, FF, SF types are counted correctly', function () {
    $relationships = collect([
        makeRel('PR_SS'),
        makeRel('PR_FF'),
        makeRel('PR_SF'),
    ]);
    $result = (new RelationshipAnalyzer)->calculate($relationships);

    expect($result['type_distribution']['SS'])->toBe(1)
        ->and($result['type_distribution']['FF'])->toBe(1)
        ->and($result['type_distribution']['SF'])->toBe(1)
        ->and($result['type_distribution']['FS'])->toBe(0);
});

test('unknown type falls into unknown bucket', function () {
    $result = (new RelationshipAnalyzer)->calculate(collect([makeRel('CUSTOM')]));

    expect($result['type_distribution']['unknown'])->toBe(1)
        ->and($result['type_distribution']['FS'])->toBe(0);
});

// ---------------------------------------------------------------
// Percentages
// ---------------------------------------------------------------

test('100% FS when all relationships are FS', function () {
    $rels = collect([makeRel('PR_FS'), makeRel('PR_FS')]);
    $result = (new RelationshipAnalyzer)->calculate($rels);

    expect($result['type_percentages']['FS'])->toBe(100.0)
        ->and($result['type_percentages']['SS'])->toBe(0.0);
});

test('type_percentages sum to 100 for mixed types', function () {
    $rels = collect([makeRel('PR_FS'), makeRel('PR_SS'), makeRel('PR_FF'), makeRel('PR_SF')]);
    $result = (new RelationshipAnalyzer)->calculate($rels);

    $sum = $result['type_percentages']['FS']
        + $result['type_percentages']['SS']
        + $result['type_percentages']['FF']
        + $result['type_percentages']['SF'];

    expect($sum)->toEqual(100.0);
});

test('percentage rounds to 2 decimal places for 1/3 split', function () {
    $rels = collect([makeRel('PR_FS'), makeRel('PR_SS'), makeRel('PR_FF')]);
    $result = (new RelationshipAnalyzer)->calculate($rels);

    // Each is 1/3 = 33.33...%
    expect($result['type_percentages']['FS'])->toBe(33.33)
        ->and($result['type_percentages']['SS'])->toBe(33.33)
        ->and($result['type_percentages']['FF'])->toBe(33.33);
});

test('FS percentage is correct for 2 of 3 relationships', function () {
    // 2/3 * 100 = 66.67
    $rels = collect([makeRel('PR_FS'), makeRel('PR_FS'), makeRel('PR_SS')]);
    $result = (new RelationshipAnalyzer)->calculate($rels);

    expect($result['type_percentages']['FS'])->toBe(66.67)
        ->and($result['type_percentages']['SS'])->toBe(33.33);
});

test('SS FF SF percentages are independently computed', function () {
    // 1/7 = 14.29%, 2/7 = 28.57%, 3/7 = 42.86%
    $rels = collect([
        makeRel('PR_SS'),
        makeRel('PR_FF'), makeRel('PR_FF'),
        makeRel('PR_SF'), makeRel('PR_SF'), makeRel('PR_SF'),
        makeRel('PR_FS'),
    ]);
    $result = (new RelationshipAnalyzer)->calculate($rels);

    expect($result['type_percentages']['FS'])->toBe(14.29)
        ->and($result['type_percentages']['SS'])->toBe(14.29)
        ->and($result['type_percentages']['FF'])->toBe(28.57)
        ->and($result['type_percentages']['SF'])->toBe(42.86);
});

// ---------------------------------------------------------------
// Lag distribution
// ---------------------------------------------------------------

test('positive lag is counted in positive bucket', function () {
    $result = (new RelationshipAnalyzer)->calculate(collect([makeRel('PR_FS', 8.0)]));

    expect($result['lag_distribution']['positive'])->toBe(1)
        ->and($result['lag_distribution']['zero'])->toBe(0)
        ->and($result['lag_distribution']['negative'])->toBe(0);
});

test('negative lag (lead) is counted in negative bucket', function () {
    $result = (new RelationshipAnalyzer)->calculate(collect([makeRel('PR_FS', -8.0)]));

    expect($result['lag_distribution']['negative'])->toBe(1)
        ->and($result['lag_distribution']['positive'])->toBe(0);
});

test('zero lag is counted in zero bucket', function () {
    $result = (new RelationshipAnalyzer)->calculate(collect([makeRel('PR_FS', 0.0)]));

    expect($result['lag_distribution']['zero'])->toBe(1)
        ->and($result['lag_distribution']['negative'])->toBe(0)
        ->and($result['lag_distribution']['positive'])->toBe(0);
});

test('null lag defaults to 0 and goes into zero bucket', function () {
    $relWithNullLag = RelationshipData::fromModelOrArray([
        'task_pred_id' => 'X1',
        'pred_type' => 'PR_FS',
        'lag_hr_cnt' => '', // empty string → null via toNullableFloat
    ]);

    $result = (new RelationshipAnalyzer)->calculate(collect([$relWithNullLag]));

    expect($result['lag_distribution']['zero'])->toBe(1)
        ->and($result['lag_distribution']['negative'])->toBe(0);
});

test('lag distribution counts add up to total relationships', function () {
    $rels = collect([
        makeRel('PR_FS', -8.0),
        makeRel('PR_SS', 0.0),
        makeRel('PR_FF', 16.0),
        makeRel('PR_SF', 4.0),
    ]);
    $result = (new RelationshipAnalyzer)->calculate($rels);

    $lagSum = $result['lag_distribution']['negative']
        + $result['lag_distribution']['zero']
        + $result['lag_distribution']['positive'];

    expect($lagSum)->toBe($result['total_relationships']);
});

test('accepts plain array input', function () {
    $result = (new RelationshipAnalyzer)->calculate([makeRel('PR_FS'), makeRel('PR_SS')]);

    expect($result['total_relationships'])->toBe(2);
});

test('runs against real XER fixture without error', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new RelationshipAnalyzer)->calculate($data->getRelationships());

    expect($result['total_relationships'])->toBeGreaterThan(0);

    $lagSum = $result['lag_distribution']['negative']
        + $result['lag_distribution']['zero']
        + $result['lag_distribution']['positive'];
    expect($lagSum)->toBe($result['total_relationships']);
});
