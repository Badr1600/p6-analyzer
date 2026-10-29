<?php

use TeckFuture\P6Analyzer\Analyzers\EarnedValueCalculator;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\Enums\EvmMethod;
use TeckFuture\P6Analyzer\IO\XerParser;

covers(EarnedValueCalculator::class);

/**
 * Build an ActivityData for EVM tests. Null-valued fields are OMITTED from
 * raw data — the absent-vs-zero distinction is load-bearing for actual-cost
 * inference, so tests must be able to express both.
 */
function makeEvActivity(
    ?float $bac = null,
    ?float $ac = null,
    ?float $remainCost = null,
    ?float $pctComplete = null,
    ?float $pv = null,
    ?float $workQty = null,
    ?float $actWorkQty = null,
    ?float $remainWorkQty = null,
    ?float $duration = null,
    ?float $remainDuration = null,
    string $status = 'TK_Active',
    ?string $pctType = null,
    ?string $taskType = null,
    ?string $targetStart = null,
    ?string $targetEnd = null,
): ActivityData {
    $row = ['task_id' => uniqid(), 'status_code' => $status];

    foreach ([
        'target_cost' => $bac,
        'act_cost' => $ac,
        'remain_cost' => $remainCost,
        'phys_comp_pct' => $pctComplete,
        'planned_value' => $pv,
        'target_work_qty' => $workQty,
        'act_work_qty' => $actWorkQty,
        'remain_work_qty' => $remainWorkQty,
        'target_drtn_hr_cnt' => $duration,
        'remain_drtn_hr_cnt' => $remainDuration,
    ] as $key => $value) {
        if ($value !== null) {
            $row[$key] = (string) $value;
        }
    }

    if ($pctType !== null) {
        $row['complete_pct_type'] = $pctType;
    }
    if ($taskType !== null) {
        $row['task_type'] = $taskType;
    }
    if ($targetStart !== null) {
        $row['target_start_date'] = $targetStart;
    }
    if ($targetEnd !== null) {
        $row['target_end_date'] = $targetEnd;
    }

    return ActivityData::fromModelOrArray($row);
}

// ---------------------------------------------------------------
// Empty input & nullable indices
// ---------------------------------------------------------------

test('empty collection returns null CPI and SPI, zero totals', function () {
    $result = (new EarnedValueCalculator)->calculate(collect());

    expect($result['cpi'])->toBeNull()
        ->and($result['spi'])->toBeNull()
        ->and($result['tcpi'])->toBeNull()
        ->and($result['earned_value'])->toBe(0.0)
        ->and($result['actual_cost'])->toBe(0.0)
        ->and($result['budget_at_completion'])->toBe(0.0)
        ->and($result['planned_value'])->toBe(0.0);
});

test('CPI is null when actual cost is unknowable (no fake 1.0)', function () {
    // Active, no tracked AC, no remaining field, 50% complete.
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 50.0),
    ]));

    expect($result['actual_cost'])->toBe(0.0)
        ->and($result['cpi'])->toBeNull()
        ->and($result['actual_cost_sources']['none'])->toBe(1);
});

test('SPI is null when no planned value exists at the data date', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 50.0),
    ]));

    expect($result['planned_value'])->toBe(0.0)
        ->and($result['spi'])->toBeNull();
});

// ---------------------------------------------------------------
// Core formulas: EV, CV, SV, CPI, SPI
// ---------------------------------------------------------------

test('earned_value is BAC multiplied by percent complete', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 50.0),
    ]));

    expect($result['earned_value'])->toBe(50.0);
});

test('totals aggregate across activities', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 100.0, ac: 100.0),
        makeEvActivity(bac: 200.0, pctComplete: 50.0, ac: 50.0),
    ]));

    expect($result['earned_value'])->toBe(200.0)
        ->and($result['budget_at_completion'])->toBe(300.0)
        ->and($result['actual_cost'])->toBe(150.0);
});

test('CPI is EV divided by tracked AC', function () {
    // EV = 100, AC = 50 → CPI = 2.0
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 50.0, pctComplete: 100.0),
    ]));

    expect($result['cpi'])->toBe(2.0)
        ->and($result['actual_cost_sources']['tracked'])->toBe(1);
});

test('SPI is EV divided by explicit PV', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 50.0, pv: 100.0),
    ]));

    expect($result['spi'])->toBe(0.5);
});

test('cost_variance is EV minus AC', function () {
    // EV = 80, AC = 90 → CV = -10
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
// EAC family: EAC, ETC, VAC, TCPI
// ---------------------------------------------------------------

test('EAC is BAC divided by CPI, ETC and VAC follow', function () {
    // BAC=200, EV=100, AC=50 → CPI=2 → EAC=100, ETC=50, VAC=100
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 200.0, ac: 50.0, pctComplete: 50.0),
    ]));

    expect($result['estimate_at_completion'])->toBe(100.0)
        ->and($result['estimate_to_complete'])->toBe(50.0)
        ->and($result['variance_at_completion'])->toBe(100.0);
});

test('EAC uses the unrounded CPI (no rounding error propagation)', function () {
    // EV = 100 × (2/3) = 66.67, AC = 100 → displayed CPI 0.67 but
    // EAC must be 100 / (2/3) = 150.0, not 100 / 0.67 = 149.25.
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 100.0, pctComplete: 200.0 / 3.0),
    ]));

    expect($result['cpi'])->toBe(0.67)
        ->and($result['estimate_at_completion'])->toBe(150.0);
});

test('EAC falls back to BAC when CPI is null', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 50.0),
    ]));

    expect($result['estimate_at_completion'])->toBe(100.0);
});

test('TCPI is remaining work over remaining budget', function () {
    // BAC=200, EV=100, AC=50 → TCPI = (200-100)/(200-50) = 0.67
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 200.0, ac: 50.0, pctComplete: 50.0),
    ]));

    expect($result['tcpi'])->toBe(0.67);
});

test('TCPI is null when budget is exhausted', function () {
    // AC = BAC → no remaining budget to perform against.
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 100.0, pctComplete: 50.0),
    ]));

    expect($result['tcpi'])->toBeNull();
});

// ---------------------------------------------------------------
// Actual-cost resolution: tracked → inferred-from-remaining → status
// ---------------------------------------------------------------

test('tracked actuals win over remaining-based inference', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 30.0, remainCost: 40.0, pctComplete: 50.0),
    ]));

    expect($result['actual_cost'])->toBe(30.0)
        ->and($result['actual_cost_sources']['tracked'])->toBe(1);
});

test('AC is inferred as BAC minus remaining when remaining is present', function () {
    // P6 tracks remaining but no actuals: AC = 100 − 40 = 60.
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, remainCost: 40.0, pctComplete: 60.0),
    ]));

    expect($result['actual_cost'])->toBe(60.0)
        ->and($result['actual_cost_sources']['inferred_from_remaining'])->toBe(1)
        ->and($result['cpi'])->toBe(1.0);
});

test('an ABSENT remaining field never triggers inference', function () {
    // Regression: remain_cost missing must not read as "zero remaining".
    // Active at 0% with no cost data at all → AC stays 0, CPI null.
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 0.0),
    ]));

    expect($result['actual_cost'])->toBe(0.0)
        ->and($result['cpi'])->toBeNull()
        ->and($result['actual_cost_sources']['inferred_from_remaining'])->toBe(0);
});

test('a PRESENT zero remaining means fully spent', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, remainCost: 0.0, pctComplete: 90.0),
    ]));

    expect($result['actual_cost'])->toBe(100.0)
        ->and($result['actual_cost_sources']['inferred_from_remaining'])->toBe(1);
});

test('inferred AC clamps to the 0..BAC range', function () {
    // Remaining exceeding BAC (re-estimated work) must not go negative.
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, remainCost: 150.0, pctComplete: 10.0),
    ]));

    expect($result['actual_cost'])->toBe(0.0);
});

test('Completed status supplies AC when nothing else does', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, status: 'TK_Complete'),
    ]));

    expect($result['actual_cost'])->toBe(100.0)
        ->and($result['actual_cost_sources']['status'])->toBe(1);
});

test('inference can be disabled via options', function () {
    $calculator = new EarnedValueCalculator(['infer_actuals_from_remaining' => false]);
    $result = $calculator->calculate(collect([
        makeEvActivity(bac: 100.0, remainCost: 40.0, pctComplete: 60.0),
    ]));

    expect($result['actual_cost'])->toBe(0.0)
        ->and($result['actual_cost_sources']['none'])->toBe(1);
});

// ---------------------------------------------------------------
// No silent unit mixing
// ---------------------------------------------------------------

test('cost method never substitutes duration for a costless activity', function () {
    // One activity with cost, one with only duration. Under the cost method
    // the second contributes NOTHING — no dollars-plus-hours sums.
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 50.0, pctComplete: 100.0),
        makeEvActivity(duration: 40.0, pctComplete: 100.0),
    ]), method: EvmMethod::Cost);

    expect($result['budget_at_completion'])->toBe(100.0)
        ->and($result['earned_value'])->toBe(100.0)
        ->and($result['coverage']['measurable_activities'])->toBe(2)
        ->and($result['coverage']['activities_with_data'])->toBe(1)
        ->and($result['coverage']['coverage_pct'])->toBe(50.0);
});

// ---------------------------------------------------------------
// Measurement methods & Auto resolution
// ---------------------------------------------------------------

test('labor units method uses native TASK work quantities', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(workQty: 80.0, actWorkQty: 20.0, remainWorkQty: 60.0, pctComplete: 25.0),
    ]), method: EvmMethod::LaborUnits);

    expect($result['method'])->toBe('labor_units')
        ->and($result['unit'])->toBe('labor_hours')
        ->and($result['budget_at_completion'])->toBe(80.0)
        ->and($result['earned_value'])->toBe(20.0)
        ->and($result['actual_cost'])->toBe(20.0)
        ->and($result['cpi'])->toBe(1.0);
});

test('labor units method ignores the cost-denominated explicit PV', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(workQty: 80.0, pctComplete: 50.0, pv: 999.0),
    ]), method: EvmMethod::LaborUnits);

    expect($result['planned_value'])->toBe(0.0);
});

test('duration method infers progress from remaining duration', function () {
    // BAC = 40h, remaining = 10h → AC = 30h.
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(duration: 40.0, remainDuration: 10.0, pctComplete: 75.0),
    ]), method: EvmMethod::Duration);

    expect($result['method'])->toBe('duration')
        ->and($result['unit'])->toBe('schedule_hours')
        ->and($result['budget_at_completion'])->toBe(40.0)
        ->and($result['actual_cost'])->toBe(30.0);
});

test('auto resolves to cost when cost coverage meets the threshold', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, workQty: 80.0, duration: 40.0, pctComplete: 10.0),
    ]));

    expect($result['method'])->toBe('cost');
});

test('auto falls back to labor units when cost coverage is too low', function () {
    // 1 of 3 with cost (0.33 < 0.5); 3 of 3 with work quantities.
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, workQty: 80.0, pctComplete: 10.0),
        makeEvActivity(workQty: 40.0, pctComplete: 0.0),
        makeEvActivity(workQty: 60.0, pctComplete: 0.0),
    ]));

    expect($result['method'])->toBe('labor_units');
});

test('auto falls back to duration when neither cost nor labor qualify', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(duration: 40.0, pctComplete: 50.0),
        makeEvActivity(duration: 24.0, pctComplete: 0.0),
    ]));

    expect($result['method'])->toBe('duration');
});

test('auto coverage threshold is configurable', function () {
    // Cost coverage 0.33 passes a 0.25 threshold.
    $calculator = new EarnedValueCalculator(['auto_coverage_threshold' => 0.25]);
    $result = $calculator->calculate(collect([
        makeEvActivity(bac: 100.0, workQty: 80.0, pctComplete: 10.0),
        makeEvActivity(workQty: 40.0, pctComplete: 0.0),
        makeEvActivity(workQty: 60.0, pctComplete: 0.0),
    ]));

    expect($result['method'])->toBe('cost');
});

// ---------------------------------------------------------------
// Percent complete: complete_pct_type & status overrides
// ---------------------------------------------------------------

test('CP_Drtn earns value from duration progress, not the stale physical pct', function () {
    // 40h target, 24h remaining → 40% duration-complete; phys says 0.
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 40.0, pctComplete: 0.0, duration: 40.0, remainDuration: 24.0, pctType: 'CP_Drtn'),
    ]));

    expect($result['earned_value'])->toBe(40.0);
});

test('CP_Units earns value from unit progress', function () {
    // 30 actual + 70 remaining → 30% units-complete.
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 30.0, actWorkQty: 30.0, remainWorkQty: 70.0, pctType: 'CP_Units'),
    ]));

    expect($result['earned_value'])->toBe(30.0);
});

test('CP_Phys and unknown types use the physical → activity pct chain', function () {
    $phys = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 50.0, pctComplete: 50.0, pctType: 'CP_Phys'),
    ]));

    expect($phys['earned_value'])->toBe(50.0);
});

test('complete_pct_type can be ignored via options', function () {
    $calculator = new EarnedValueCalculator(['respect_complete_pct_type' => false]);
    $result = $calculator->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 40.0, pctComplete: 10.0, duration: 40.0, remainDuration: 24.0, pctType: 'CP_Drtn'),
    ]));

    // Physical 10% used instead of 40% duration progress.
    expect($result['earned_value'])->toBe(10.0);
});

test('Completed activities earn full BAC even with a stale percent field', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 50.0, status: 'TK_Complete'),
    ]));

    expect($result['earned_value'])->toBe(100.0);
});

test('Not Started activities earn nothing even with a stray percent field', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 30.0, status: 'TK_NotStart'),
    ]));

    expect($result['earned_value'])->toBe(0.0);
});

test('status overrides can be disabled via options', function () {
    $calculator = new EarnedValueCalculator(['status_overrides_percent' => false]);
    $result = $calculator->calculate(collect([
        makeEvActivity(bac: 100.0, pctComplete: 50.0, status: 'TK_Complete'),
    ]));

    expect($result['earned_value'])->toBe(50.0);
});

test('percent complete clamps to the 0..100 range', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 100.0, pctComplete: 250.0),
    ]));

    expect($result['earned_value'])->toBe(100.0);
});

// ---------------------------------------------------------------
// Planned value: explicit vs time-phased
// ---------------------------------------------------------------

test('explicit planned_value wins over estimation', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(
            bac: 100.0, pctComplete: 50.0, pv: 75.0,
            targetStart: '2024-01-01', targetEnd: '2024-01-11',
        ),
    ]), dataDate: '2024-01-06');

    expect($result['planned_value'])->toBe(75.0);
});

test('PV is time-phased linearly between target dates', function () {
    // Halfway through a 10-day window → PV = 50% of BAC.
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(
            bac: 100.0, ac: 40.0, pctComplete: 40.0,
            targetStart: '2024-01-01', targetEnd: '2024-01-11',
        ),
    ]), dataDate: '2024-01-06');

    expect($result['planned_value'])->toBe(50.0)
        ->and($result['spi'])->toBe(0.8);
});

test('PV is zero before the target start and full BAC after the target end', function () {
    $activity = fn () => makeEvActivity(
        bac: 100.0, pctComplete: 0.0,
        targetStart: '2024-01-10', targetEnd: '2024-01-20',
    );

    $before = (new EarnedValueCalculator)->calculate(collect([$activity()]), dataDate: '2024-01-05');
    $after = (new EarnedValueCalculator)->calculate(collect([$activity()]), dataDate: '2024-02-01');

    expect($before['planned_value'])->toBe(0.0)
        ->and($after['planned_value'])->toBe(100.0);
});

test('without a data date PV stays 0 and SPI is null', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(
            bac: 100.0, pctComplete: 50.0,
            targetStart: '2024-01-01', targetEnd: '2024-01-11',
        ),
    ]));

    expect($result['planned_value'])->toBe(0.0)
        ->and($result['spi'])->toBeNull();
});

// ---------------------------------------------------------------
// Exclusions: WBS summary bars and milestones
// ---------------------------------------------------------------

test('WBS summary activities are excluded from sums', function () {
    // TT_WBS duplicates its children's rollup — including it double-counts.
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 50.0, pctComplete: 100.0),
        makeEvActivity(bac: 100.0, ac: 50.0, pctComplete: 100.0, taskType: 'TT_WBS'),
    ]));

    expect($result['budget_at_completion'])->toBe(100.0)
        ->and($result['coverage']['measurable_activities'])->toBe(1);
});

test('milestones are excluded from coverage denominators', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 100.0, pctComplete: 100.0),
        makeEvActivity(status: 'TK_NotStart', taskType: 'TT_FinMile'),
        makeEvActivity(status: 'TK_NotStart', taskType: 'TT_Mile'),
    ]));

    expect($result['coverage']['total_activities'])->toBe(3)
        ->and($result['coverage']['measurable_activities'])->toBe(1)
        ->and($result['coverage']['coverage_pct'])->toBe(100.0);
});

// ---------------------------------------------------------------
// coverage_pct is a 0-100 percentage
// ---------------------------------------------------------------

test('coverage_pct is a percentage rounded to 2 decimal places', function (int $withData, int $measurable, float $expected) {
    $activities = collect([
        ...array_fill(0, $withData, makeEvActivity(bac: 100.0, ac: 50.0, pctComplete: 50.0)),
        ...array_fill(0, $measurable - $withData, makeEvActivity(duration: 40.0)), // no cost data
    ]);

    $coverage = (new EarnedValueCalculator)->calculate($activities, method: EvmMethod::Cost)['coverage'];

    expect($coverage['measurable_activities'])->toBe($measurable)
        ->and($coverage['activities_with_data'])->toBe($withData)
        ->and($coverage['coverage_pct'])->toBe($expected);
})->with([
    'a third' => [1, 3, 33.33],
    'two thirds' => [2, 3, 66.67],
    'all of them' => [4, 4, 100.0],
    'none of them' => [0, 4, 0.0],
]);

test('coverage_pct is 0 when there is nothing measurable', function () {
    $coverage = (new EarnedValueCalculator)->calculate(collect())['coverage'];

    expect($coverage['measurable_activities'])->toBe(0)
        ->and($coverage['coverage_pct'])->toBe(0.0);
});

test('coverage_pct on the real XER fixture is a percentage of the measurable activities', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());

    $coverage = (new EarnedValueCalculator)->calculate($data->getActivities(), '2024-06-15', EvmMethod::Cost)['coverage'];

    // 275 of the 384 measurable activities carry cost data
    expect($coverage['measurable_activities'])->toBe(384)
        ->and($coverage['activities_with_data'])->toBe(275)
        ->and($coverage['coverage_pct'])->toBe(71.61);
});

test('the report shows coverage as a percentage while auto still compares the 0 to 1 share', function () {
    // Cost covers 1 of 3 activities: 33.33 as a percentage, 0.33 as a share. Auto compares the share
    // with the 0.5 threshold, so it passes over cost; the 33.33 would wrongly have cleared 0.5.
    $report = (new EarnedValueCalculator)->report(collect([
        makeEvActivity(bac: 100.0, workQty: 80.0, pctComplete: 10.0),
        makeEvActivity(workQty: 40.0, pctComplete: 0.0),
        makeEvActivity(workQty: 60.0, pctComplete: 0.0),
    ]));

    expect($report['by_cost']['coverage']['coverage_pct'])->toBe(33.33)
        ->and($report['by_labor_units']['coverage']['coverage_pct'])->toBe(100.0)
        ->and($report['recommended'])->toBe('labor_units');
});

// ---------------------------------------------------------------
// report(): all methods in one pass
// ---------------------------------------------------------------

test('report returns every method plus a recommendation', function () {
    $report = (new EarnedValueCalculator)->report(collect([
        makeEvActivity(bac: 100.0, ac: 50.0, pctComplete: 100.0, workQty: 80.0, actWorkQty: 40.0, duration: 40.0),
    ]), '2024-06-15');

    expect($report['data_date'])->toBe('2024-06-15')
        ->and($report['by_cost']['method'])->toBe('cost')
        ->and($report['by_cost']['budget_at_completion'])->toBe(100.0)
        ->and($report['by_labor_units']['method'])->toBe('labor_units')
        ->and($report['by_labor_units']['budget_at_completion'])->toBe(80.0)
        ->and($report['by_duration']['method'])->toBe('duration')
        ->and($report['by_duration']['budget_at_completion'])->toBe(40.0)
        ->and($report['recommended'])->toBe('cost');
});

// ---------------------------------------------------------------
// Input handling, rounding, fixture
// ---------------------------------------------------------------

test('accepts plain array input', function () {
    $result = (new EarnedValueCalculator)->calculate([
        makeEvActivity(bac: 100.0, ac: 100.0, pctComplete: 100.0),
    ]);

    expect($result['cpi'])->toBe(1.0);
});

test('monetary values round to 2 decimal places', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 100.0 / 3.0, pctComplete: 100.0 / 3.0),
    ]));

    expect($result['earned_value'])->toBe(33.33)
        ->and($result['actual_cost'])->toBe(33.33);
});

test('CPI and SPI round to 2 decimal places', function () {
    $result = (new EarnedValueCalculator)->calculate(collect([
        makeEvActivity(bac: 100.0, ac: 100.0, pctComplete: 200.0 / 3.0, pv: 100.0),
    ]));

    expect($result['cpi'])->toBe(0.67)
        ->and($result['spi'])->toBe(0.67);
});

test('runs against the real XER fixture without error', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new EarnedValueCalculator)->calculate($data->getActivities(), '2024-06-15');

    expect($result)->toHaveKeys([
        'method', 'unit', 'planned_value', 'earned_value', 'actual_cost',
        'budget_at_completion', 'cost_variance', 'schedule_variance',
        'cpi', 'spi', 'estimate_at_completion', 'estimate_to_complete',
        'variance_at_completion', 'tcpi', 'coverage', 'actual_cost_sources',
    ]);
});
