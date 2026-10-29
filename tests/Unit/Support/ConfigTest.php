<?php

use Illuminate\Container\Container;
use TeckFuture\P6Analyzer\Support\Config;

covers(Config::class);

test('returns the value configured under the p6-analyzer key', function () {
    config(['p6-analyzer.critical_float_threshold' => 16]);

    expect(Config::get('critical_float_threshold', 0))->toBe(16);
});

test('reads nested keys with dot notation', function () {
    config(['p6-analyzer.evm.default_method' => 'cost']);

    expect(Config::get('evm.default_method'))->toBe('cost');
});

test('returns the default when the key is not configured', function () {
    expect(Config::get('not_a_real_key', 'fallback'))->toBe('fallback');
});

test('the default is null when none is given', function () {
    expect(Config::get('not_a_real_key'))->toBeNull();
});

test('returns the default instead of throwing when config cannot be resolved', function () {
    $app = Container::getInstance();

    // A bare container has no "config" binding: the situation of standalone use outside a booted Laravel application.
    Container::setInstance(new Container);

    try {
        expect(Config::get('critical_float_threshold', 7))->toBe(7)
            ->and(Config::get('evm.default_method'))->toBeNull();
    } finally {
        Container::setInstance($app);
    }
});
