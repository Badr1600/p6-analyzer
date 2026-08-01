<?php

use TeckFuture\P6Analyzer\Enums\EvmMethod;

covers(EvmMethod::class);

test('fromString coerces valid strings', function () {
    expect(EvmMethod::fromString('cost'))->toBe(EvmMethod::Cost)
        ->and(EvmMethod::fromString('labor_units'))->toBe(EvmMethod::LaborUnits)
        ->and(EvmMethod::fromString('duration'))->toBe(EvmMethod::Duration)
        ->and(EvmMethod::fromString('auto'))->toBe(EvmMethod::Auto);
});

test('fromString passes enum instances through', function () {
    expect(EvmMethod::fromString(EvmMethod::Cost))->toBe(EvmMethod::Cost);
});

test('fromString falls back to the default for null, empty, and unknown values', function () {
    expect(EvmMethod::fromString(null))->toBe(EvmMethod::Auto)
        ->and(EvmMethod::fromString(''))->toBe(EvmMethod::Auto)
        ->and(EvmMethod::fromString('bogus'))->toBe(EvmMethod::Auto)
        ->and(EvmMethod::fromString(null, EvmMethod::Cost))->toBe(EvmMethod::Cost);
});

test('each method reports its unit', function () {
    expect(EvmMethod::Cost->unit())->toBe('currency')
        ->and(EvmMethod::LaborUnits->unit())->toBe('labor_hours')
        ->and(EvmMethod::Duration->unit())->toBe('schedule_hours');
});
