<?php

use TeckFuture\P6Analyzer\Analyzers\EarnedValueCalculator;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\IO\XerParser;

covers(EarnedValueCalculator::class);

function makeEvActivity(
    float $bac = 0.0,
    float $ac = 0.0,
    float $pctComplete = 0.0,
    float $pv = 0.0,
): ActivityData {
    return ActivityData::fromModelOrArray([
        'task_id' => uniqid(),
        'status_code' => 'TK_Active',
        'target_cost' => (string) $bac,
        'act_cost' => (string) $ac,
        'phys_comp_pct' => (string) $pctComplete,
        'planned_value' => (string) $pv,
        'target_drtn_hr_cnt' => '40',
    ]);
}

// ---------------------------------------------------------------
// Empty input defaults
// ---------------------------------------------------------------

test('empty collection returns default 1.0 CPI and SPI', function () {
    $result = (new EarnedValueCalculator)->calculate(collect());

    expect($result['cpi'])->toBe(1.0)
        ->and($result['spi'])->toBe(1.0)
        ->and($result['earned_value'])->toBe(0.0)
        ->and($result['actual_cost'])->toBe(0.0)
        ->and($result['budget_at_completion'])->toBe(0.0)
        ->and($result['planned_value'])->toBe(0.0);
});

// ---------------------------------------------------------------
// EV calculation: EV = BAC * (pct / 100)
// ---------------------------------------------------------------

test('earned_value is BAC multiplied by percent complete / 100', function () {
    // BAC=100, 50% complete → EV = 50
    $result = (new EarnedValueCalculator)->calculate(collect([makeEvActivity(bac: 100.0, pctComplete: 50.0)]));

    expect($result['earned_value'])->toBe(50.0);
});

test('earned_value aggregates across all activities', function () {
    $activities = collect([
        makeEvActivity(bac: 100.0, pctComplete: 100.0), // EV = 100
        makeEvActivity(bac: 200.0, pctComplete: 50.0),  // EV = 100
    ]);
    $result = (new EarnedValueCalculator)->calculate($activities);

    expect($result['earned_value'])->toBe(200.0)
        ->and($result['budget_at_completion'])->toBe(300.0);
});

// ---------------------------------------------------------------
// CPI: EV / AC
// ---------------------------------------------------------------

test('CPI is 1.0 when earned equals actual cost', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 100.0, pctComplete: 100.0),
    ]));

    expect($result['cpi'])->toBe(1.0);
});

test('CPI is 2.0 when earned is double actual cost', function () {
    // EV = 100, AC = 50 → CPI = 2.0
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 50.0, pctComplete: 100.0),
    ]));

    expect($result['cpi'])->toBe(2.0);
});

test('CPI is 1.0 when AC is zero (no divide by zero)', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 0.0, pctComplete: 50.0),
    ]));

    expect($result['cpi'])->toBe(1.0);
});

// ---------------------------------------------------------------
// SPI: EV / PV
// ---------------------------------------------------------------

test('SPI is 1.0 when earned equals planned value', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 50.0, pv: 50.0),
    ]));

    expect($result['spi'])->toBe(1.0);
});

test('SPI is 1.0 when PV is zero (no divide by zero)', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 50.0, pv: 0.0),
    ]));

    expect($result['spi'])->toBe(1.0);
});

// ---------------------------------------------------------------
// Variances: CV = EV - AC, SV = EV - PV
// ---------------------------------------------------------------

test('cost_variance is EV minus AC', function () {
    // EV = 80 (100*80%), AC = 90 → CV = -10
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 90.0, pctComplete: 80.0),
    ]));

    expect($result['cost_variance'])->toBe(-10.0);
});

test('schedule_variance is EV minus PV', function () {
    // EV = 80, PV = 100 → SV = -20
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 80.0, pv: 100.0),
    ]));

    expect($result['schedule_variance'])->toBe(-20.0);
});

// ---------------------------------------------------------------
// EAC: BAC / CPI
// ---------------------------------------------------------------

test('EAC is BAC divided by CPI', function () {
    // BAC=200, EV=100, AC=50 → CPI=2 → EAC=100
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 200.0, ac: 50.0, pctComplete: 50.0),
    ]));

    expect($result['estimate_at_completion'])->toBe(100.0)
        ->and($result['cpi'])->toBe(2.0);
});

test('accepts plain array input', function () {
    $result = (new EarnedValueCalculator)->calculate([makeEvActivity(bac: 100.0, pctComplete: 100.0, ac: 100.0)]);

    expect($result['cpi'])->toBe(1.0);
});

// ---------------------------------------------------------------
// Precision / rounding — kills round($x, 2) → round($x, 1|3)
// ---------------------------------------------------------------

test('CPI rounds to exactly 2 decimal places', function () {
    // EV = 100 * (2/3) = 66.666..., AC = 100 → CPI = 0.67 (not 0.7 at 1dp or 0.667 at 3dp)
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 100.0, pctComplete: 200.0 / 3.0),
    ]));

    expect($result['cpi'])->toBe(0.67);
});

test('SPI rounds to exactly 2 decimal places', function () {
    // EV = 100 * 0.333 = 33.3, PV = 100 → SPI = 0.33
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 100.0 / 3.0, pv: 100.0),
    ]));

    expect($result['spi'])->toBe(0.33);
});

test('earned_value rounds to 2 decimal places', function () {
    // BAC=100, pct=1/3 → EV = 33.333... → rounded to 33.33
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 100.0 / 3.0),
    ]));

    expect($result['earned_value'])->toBe(33.33);
});

test('planned_value rounds to 2 decimal places', function () {
    // PV as direct fractional value
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(pv: 100.0 / 3.0),
    ]));

    expect($result['planned_value'])->toBe(33.33);
});

test('actual_cost rounds to 2 decimal places', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(ac: 100.0 / 3.0),
    ]));

    expect($result['actual_cost'])->toBe(33.33);
});

test('budget_at_completion rounds to 2 decimal places', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0 / 3.0),
    ]));

    expect($result['budget_at_completion'])->toBe(33.33);
});

test('cost_variance rounds to 2 decimal places', function () {
    // CV = EV - AC = 33.33 - 33.33 ... actually needs fractional CV
    // EV = 100 * (1/3) = 33.333, AC = 10 → CV = 23.333... → 23.33
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 10.0, pctComplete: 100.0 / 3.0),
    ]));

    expect($result['cost_variance'])->toBe(23.33);
});

test('schedule_variance rounds to 2 decimal places', function () {
    // EV = 33.333, PV = 10 → SV = 23.333 → 23.33
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 100.0 / 3.0, pv: 10.0),
    ]));

    expect($result['schedule_variance'])->toBe(23.33);
});

// ---------------------------------------------------------------
// > 0 guard (kills GreaterToGreaterOrEqual)
// ---------------------------------------------------------------

test('CPI guard: when AC > 0 performs division, when AC = 0 returns 1.0', function () {
    // AC = 1 → CPI = EV/AC (not 1.0 default)
    $withAc = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 1.0, pctComplete: 100.0),
    ]));
    // AC = 0 → CPI = 1.0
    $withoutAc = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 0.0, pctComplete: 100.0),
    ]));

    expect($withAc['cpi'])->toBe(100.0) // EV=100, AC=1
        ->and($withoutAc['cpi'])->toBe(1.0);
});

test('estimate_at_completion guard: when CPI > 0 divides, else uses BAC', function () {
    // Normal case: EAC = BAC/CPI = 200/2 = 100
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 200.0, ac: 50.0, pctComplete: 50.0),
    ]));

    expect($result['estimate_at_completion'])->toBe(100.0);
});

// ---------------------------------------------------------------
// String-to-float cast assertions
// ---------------------------------------------------------------

test('string numeric BAC is cast to float correctly', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 300.0, pctComplete: 100.0),
    ]));

    expect($result['budget_at_completion'])->toBe(300.0)->toBeFloat();
});

test('phys_comp_pct takes precedence over complete_pct when both present', function () {
    // phys_comp_pct=100 should be used, not complete_pct=0
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'status_code' => 'TK_Active',
        'target_cost' => '100',
        'act_cost' => '50',
        'phys_comp_pct' => '100',
        'complete_pct' => '0',
        'target_drtn_hr_cnt' => '40',
    ]);

    $result = (new EarnedValueCalculator)->calculate(collect([$activity]));

    // EV = 100 * 100% = 100, AC = 50 → CPI = 2.0 (not 1.0 if complete_pct was used)
    expect($result['cpi'])->toBe(2.0);
});

test('planned_value falls back to 0 when field is absent', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 50.0, pv: 0.0),
    ]));

    // No PV → SPI = 1.0 (default)
    expect($result['spi'])->toBe(1.0);
});

// ---------------------------------------------------------------
// Null field fallback (?? 0) — kills DecrementInteger ?? 0 → -1
// ---------------------------------------------------------------

test('activity with null target_cost contributes 0 to BAC', function () {
    // Creates activity without target_cost — forces the ?? 0 path
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'status_code' => 'TK_Active',
        'phys_comp_pct' => '100',
        'target_drtn_hr_cnt' => '40',
        // No target_cost — null path
    ]);

    $result = (new EarnedValueCalculator)->calculate(collect([$activity]));

    // BAC = 0, EV = 0, AC = 0 → CPI = 1.0, BAC = 0
    expect($result['budget_at_completion'])->toBe(0.0)
        ->and($result['earned_value'])->toBe(0.0);
});

test('activity with null act_cost contributes 0 to actual_cost', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'status_code' => 'TK_Active',
        'target_cost' => '100',
        'phys_comp_pct' => '0',
        'target_drtn_hr_cnt' => '40',
        // No act_cost — null path
    ]);

    $result = (new EarnedValueCalculator)->calculate(collect([$activity]));

    expect($result['actual_cost'])->toBe(0.0); // Not -1
});

test('activity with null phys_comp_pct uses complete_pct or 0', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'status_code' => 'TK_Active',
        'target_cost' => '100',
        'target_drtn_hr_cnt' => '40',
        // No phys_comp_pct, no complete_pct → 0
    ]);

    $result = (new EarnedValueCalculator)->calculate(collect([$activity]));

    // pct = 0 → EV = 0 (not -1 as the mutation would give -1%)
    expect($result['earned_value'])->toBe(0.0);
});

test('activity with null planned_value contributes 0 to PV sum', function () {
    $activity = ActivityData::fromModelOrArray([
        'task_id' => '1',
        'status_code' => 'TK_Active',
        'target_drtn_hr_cnt' => '40',
        // No planned_value — null path → pv = 0
    ]);

    $result = (new EarnedValueCalculator)->calculate(collect([$activity]));

    expect($result['planned_value'])->toBe(0.0); // Not -1
});

// ---------------------------------------------------------------
// > 0 guard IncrementInteger (> 0 → > 1): AC = 1 boundary
// ---------------------------------------------------------------

test('CPI uses division when AC equals 1 (not zero)', function () {
    // > 0 is true for AC=1; > 1 would be false → fallback 1.0
    // EV = 100 * 100% = 100, AC = 1 → CPI = 100.0
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 1.0, pctComplete: 100.0),
    ]));

    expect($result['cpi'])->toBe(100.0); // Not 1.0 (the fallback)
});

// ---------------------------------------------------------------
// EAC when CPI is between 0 and 1 (kills > 0 → > 1 on line 46)
// ---------------------------------------------------------------

test('EAC divides BAC by CPI when CPI is 0.5 (less than 1)', function () {
    // EV=50, AC=100 → CPI=0.5 → EAC = BAC/CPI = 200/0.5 = 400
    // If mutation changes > 0 to > 1, CPI=0.5 would use BAC directly = 200
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 200.0, ac: 100.0, pctComplete: 25.0), // EV=50, CPI=0.5
    ]));

    expect($result['estimate_at_completion'])->toBe(400.0);
});

// ---------------------------------------------------------------
// EAC round precision (kills DecrementInteger on round(x, 2))
// ---------------------------------------------------------------

test('estimate_at_completion rounds to 2 decimal places', function () {
    // BAC=100, CPI=0.67 (from 2/3) → EAC = 100/0.67 = 149.2537... → 149.25
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 100.0, pctComplete: 200.0 / 3.0), // EV=66.67, CPI=0.67
    ]));

    // EAC = 100/0.67 = 149.2537... → 149.25
    expect($result['estimate_at_completion'])->toBe(149.25);
});

test('runs against real XER fixture without error', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new EarnedValueCalculator)->calculate($data->getActivities());

    expect($result)->toHaveKeys(['cpi', 'spi', 'earned_value', 'actual_cost', 'budget_at_completion', 'cost_variance', 'schedule_variance', 'estimate_at_completion']);
});
