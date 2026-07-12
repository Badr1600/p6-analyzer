<?php

use TeckFuture\P6Analyzer\Analyzers\FloatAnalyzer;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\IO\XerParser;

covers(FloatAnalyzer::class);

function makeFloatActivity(?float $floatHr): ActivityData
{
    return ActivityData::fromModelOrArray([
        'task_id' => uniqid(),
        'status_code' => 'TK_Active',
        'total_float_hr_cnt' => $floatHr !== null ? (string) $floatHr : null,
        'target_drtn_hr_cnt' => '40',
    ]);
}

// ---------------------------------------------------------------
// Empty input
// ---------------------------------------------------------------

test('empty collection returns zero totals', function () {
    $result = (new FloatAnalyzer)->calculate(collect());

    expect($result['total_activities'])->toBe(0)
        ->and($result['distribution']['negative'])->toBe(0)
        ->and($result['distribution']['zero_to_ten'])->toBe(0)
        ->and($result['distribution']['eleven_to_thirty'])->toBe(0)
        ->and($result['distribution']['thirty_plus'])->toBe(0)
        ->and($result['distribution']['missing'])->toBe(0);
});

// ---------------------------------------------------------------
// Null float → missing
// ---------------------------------------------------------------

test('null float is counted as missing', function () {
    $result = (new FloatAnalyzer)->calculate(collect([makeFloatActivity(null)]));

    expect($result['distribution']['missing'])->toBe(1)
        ->and($result['distribution']['negative'])->toBe(0)
        ->and($result['distribution']['zero_to_ten'])->toBe(0);
});

// ---------------------------------------------------------------
// Negative float
// ---------------------------------------------------------------

test('negative float hours are counted as negative', function () {
    // -8 hrs / 8 = -1 day (negative)
    $result = (new FloatAnalyzer)->calculate(collect([makeFloatActivity(-8.0)]));

    expect($result['distribution']['negative'])->toBe(1)
        ->and($result['distribution']['zero_to_ten'])->toBe(0);
});

// ---------------------------------------------------------------
// zero_to_ten bucket (0 to 80 hrs inclusive = 0 to 10 days)
// ---------------------------------------------------------------

test('zero float days goes into zero_to_ten', function () {
    $result = (new FloatAnalyzer)->calculate(collect([makeFloatActivity(0.0)]));

    expect($result['distribution']['zero_to_ten'])->toBe(1)
        ->and($result['distribution']['negative'])->toBe(0);
});

test('80 hrs float (exactly 10 days) goes into zero_to_ten', function () {
    $result = (new FloatAnalyzer)->calculate(collect([makeFloatActivity(80.0)]));

    expect($result['distribution']['zero_to_ten'])->toBe(1)
        ->and($result['distribution']['eleven_to_thirty'])->toBe(0);
});

// ---------------------------------------------------------------
// eleven_to_thirty bucket (> 80 and <= 240 hrs = 11–30 days)
// ---------------------------------------------------------------

test('88 hrs float (11 days) goes into eleven_to_thirty', function () {
    $result = (new FloatAnalyzer)->calculate(collect([makeFloatActivity(88.0)]));

    expect($result['distribution']['eleven_to_thirty'])->toBe(1)
        ->and($result['distribution']['zero_to_ten'])->toBe(0);
});

test('240 hrs float (exactly 30 days) goes into eleven_to_thirty', function () {
    $result = (new FloatAnalyzer)->calculate(collect([makeFloatActivity(240.0)]));

    expect($result['distribution']['eleven_to_thirty'])->toBe(1)
        ->and($result['distribution']['thirty_plus'])->toBe(0);
});

// ---------------------------------------------------------------
// thirty_plus bucket (> 240 hrs = > 30 days)
// ---------------------------------------------------------------

test('248 hrs float (31 days) goes into thirty_plus', function () {
    $result = (new FloatAnalyzer)->calculate(collect([makeFloatActivity(248.0)]));

    expect($result['distribution']['thirty_plus'])->toBe(1)
        ->and($result['distribution']['eleven_to_thirty'])->toBe(0);
});

// ---------------------------------------------------------------
// Mixed input — counts and totals
// ---------------------------------------------------------------

test('distribution counts add up to total_activities', function () {
    $activities = collect([
        makeFloatActivity(null),  // missing
        makeFloatActivity(-8.0),  // negative
        makeFloatActivity(0.0),   // zero_to_ten
        makeFloatActivity(80.0),  // zero_to_ten
        makeFloatActivity(88.0),  // eleven_to_thirty
        makeFloatActivity(248.0), // thirty_plus
    ]);

    $result = (new FloatAnalyzer)->calculate($activities);
    $dist = $result['distribution'];
    $sum = $dist['negative'] + $dist['zero_to_ten'] + $dist['eleven_to_thirty'] + $dist['thirty_plus'] + $dist['missing'];

    expect($result['total_activities'])->toBe(6)
        ->and($sum)->toBe(6)
        ->and($dist['missing'])->toBe(1)
        ->and($dist['negative'])->toBe(1)
        ->and($dist['zero_to_ten'])->toBe(2)
        ->and($dist['eleven_to_thirty'])->toBe(1)
        ->and($dist['thirty_plus'])->toBe(1);
});

test('accepts plain array input', function () {
    $result = (new FloatAnalyzer)->calculate([makeFloatActivity(0.0)]);

    expect($result['total_activities'])->toBe(1);
});

test('runs against real XER fixture', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new FloatAnalyzer)->calculate($data->getActivities());

    $dist = $result['distribution'];
    $sum = $dist['negative'] + $dist['zero_to_ten'] + $dist['eleven_to_thirty'] + $dist['thirty_plus'] + $dist['missing'];
    expect($sum)->toBe($result['total_activities']);
});
