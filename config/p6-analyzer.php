<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Critical Path Threshold
    |--------------------------------------------------------------------------
    |
    | Activities with total float (in hours) at or below this threshold
    | are considered critical. Default is 0 hours.
    |
    */
    'critical_float_threshold' => 0,

    /*
    |--------------------------------------------------------------------------
    | High Float Threshold
    |--------------------------------------------------------------------------
    |
    | Activities with total float exceeding this value (in hours) are
    | flagged as having high float. Default is 352 hours (44 days × 8h).
    |
    */
    'high_float_threshold' => 352,

    /*
    |--------------------------------------------------------------------------
    | High Duration Threshold
    |--------------------------------------------------------------------------
    |
    | Activities with target duration exceeding this value (in hours) are
    | flagged as having high duration. Default is 352 hours (44 days × 8h).
    |
    */
    'high_duration_threshold' => 352,

    /*
    |--------------------------------------------------------------------------
    | DCMA Penalty Weights
    |--------------------------------------------------------------------------
    |
    | Weights used in the DCMA 14-point overall score calculation.
    | Higher weights result in larger score penalties.
    |
    */
    'dcma_penalty_weights' => [
        'high_float' => 1,
        'negative_float' => 2,
        'high_duration' => 1,
        'invalid_dates' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Resource Over-Allocation Daily Limit
    |--------------------------------------------------------------------------
    |
    | The maximum number of hours a resource can be allocated per day
    | before being flagged as over-allocated. Default is 8 hours.
    |
    */
    'resource_overallocation_daily_limit' => 8,

    /*
    |--------------------------------------------------------------------------
    | Earned Value Management
    |--------------------------------------------------------------------------
    |
    | default_method: measurement basis used when none is passed explicitly —
    |   'cost', 'labor_units', 'duration', or 'auto' (pick the best-covered
    |   method per project; never mixes units across activities).
    |
    | auto_coverage_threshold: minimum share (0–1) of measurable activities
    |   that must carry data for 'auto' to select cost or labor units before
    |   falling back to duration.
    |
    | infer_actuals_from_remaining: when a schedule tracks remaining amounts
    |   but never records actuals (a common P6 setup), derive
    |   AC = BAC − remaining for activities that carry a remaining value.
    |
    | respect_complete_pct_type: honor each activity's P6 complete_pct_type
    |   (CP_Phys / CP_Drtn / CP_Units) when selecting the percent complete
    |   that earns value.
    |
    | status_overrides_percent: Completed activities earn 100% and
    |   Not Started activities earn 0%, regardless of stale percent fields.
    |
    */
    'evm' => [
        'default_method' => 'auto',
        'auto_coverage_threshold' => 0.5,
        'infer_actuals_from_remaining' => true,
        'respect_complete_pct_type' => true,
        'status_overrides_percent' => true,
    ],
];
