<?php

use TeckFuture\P6Analyzer\Analyzers\EarnedValueCalculator;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\IO\XerParser;

test('it calculates EVM from real XER data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new EarnedValueCalculator)->calculate($data->getActivities());

    expect($result)->toHaveKeys(['planned_value', 'earned_value', 'actual_cost', 'cpi', 'spi', 'estimate_at_completion']);
});

test('it handles empty collection', function () {
    $result = (new EarnedValueCalculator)->calculate(collect());

    expect($result['earned_value'])->toBe(0.0)
        ->and($result['actual_cost'])->toBe(0.0)
        ->and($result['cpi'])->toBe(1.0)
        ->and($result['spi'])->toBe(1.0);
});

test('CPI defaults to 1 when actual cost is zero', function () {
    $activities = collect([
        ActivityData::fromModelOrArray([
            'task_id' => '1',
            'target_cost' => '1000',
            'act_cost' => '0',
            'phys_comp_pct' => '50',
        ]),
    ]);

    $result = (new EarnedValueCalculator)->calculate($activities);

    expect($result['cpi'])->toBe(1.0)
        ->and($result['earned_value'])->toBe(500.0);
});
