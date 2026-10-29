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

test('fromString falls back to the default for null and empty values', function () {
    expect(EvmMethod::fromString(null))->toBe(EvmMethod::Auto)
        ->and(EvmMethod::fromString(''))->toBe(EvmMethod::Auto)
        ->and(EvmMethod::fromString(null, EvmMethod::Cost))->toBe(EvmMethod::Cost)
        ->and(EvmMethod::fromString('', EvmMethod::Duration))->toBe(EvmMethod::Duration);
});

test('fromString rejects an unknown method and lists the valid ones', function (string $value) {
    expect(fn () => EvmMethod::fromString($value))
        ->toThrow(InvalidArgumentException::class, sprintf('Unknown EVM method "%s". Valid values: cost, labor_units, duration, auto.', $value));
})->with([
    'a typo' => 'bogus',
    'a different case' => 'Cost',
    'padded with a space' => ' cost',
    'a dash for the underscore' => 'labor-units',
    'a string zero' => '0',
]);

test('fromString does not fall back to a supplied default for an unknown method', function () {
    EvmMethod::fromString('bogus', EvmMethod::Cost);
})->throws(InvalidArgumentException::class, 'Unknown EVM method "bogus"');

test('each method reports its unit', function () {
    expect(EvmMethod::Cost->unit())->toBe('currency')
        ->and(EvmMethod::LaborUnits->unit())->toBe('labor_hours')
        ->and(EvmMethod::Duration->unit())->toBe('schedule_hours');
});
