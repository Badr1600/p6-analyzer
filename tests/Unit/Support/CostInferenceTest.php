<?php

use TeckFuture\P6Analyzer\Support\CostInference;

covers(CostInference::class);

test('tracked actuals always win', function () {
    expect(CostInference::inferActual(1000.0, 300.0, 500.0))->toBe(300.0);
});

test('infers planned minus remaining when actuals are untracked', function () {
    expect(CostInference::inferActual(1000.0, 0.0, 400.0))->toBe(600.0);
});

test('aggregate zero remaining is treated as no data, not complete', function () {
    expect(CostInference::inferActual(1000.0, 0.0, 0.0))->toBe(0.0);
});

test('null remaining never triggers inference', function () {
    expect(CostInference::inferActual(1000.0, 0.0, null))->toBe(0.0);
});

test('remaining exceeding planned clamps to zero', function () {
    expect(CostInference::inferActual(1000.0, 0.0, 1500.0))->toBe(0.0);
});

test('zero planned blocks inference', function () {
    expect(CostInference::inferActual(0.0, 0.0, 400.0))->toBe(0.0);
});
