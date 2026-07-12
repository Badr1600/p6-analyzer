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
];
