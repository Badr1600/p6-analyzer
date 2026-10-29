<?php

use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\Support\PercentComplete;

covers(PercentComplete::class);

/**
 * Build an ActivityData from raw XER fields. An In Progress activity unless
 * told otherwise, so the status overrides stay out of the way.
 *
 * @param  array<string, string>  $fields
 */
function makePcActivity(array $fields = [], string $status = 'TK_Active'): ActivityData
{
    return ActivityData::fromModelOrArray(['task_id' => '1', 'status_code' => $status] + $fields);
}

// ---------------------------------------------------------------
// Status overrides
// ---------------------------------------------------------------

test('a Completed activity is 100 whatever its percent fields say', function () {
    $activity = makePcActivity(['phys_complete_pct' => '50', 'complete_pct_type' => 'CP_Drtn', 'target_drtn_hr_cnt' => '40', 'remain_drtn_hr_cnt' => '40'], 'TK_Complete');

    expect(PercentComplete::resolve($activity))->toBe(100.0);
});

test('a Not Started activity is 0 whatever its percent fields say', function () {
    $activity = makePcActivity(['phys_complete_pct' => '30', 'complete_pct' => '30'], 'TK_NotStart');

    expect(PercentComplete::resolve($activity))->toBe(0.0);
});

test('an In Progress activity is not overridden', function () {
    expect(PercentComplete::resolve(makePcActivity(['phys_complete_pct' => '30'])))->toBe(30.0);
});

test('status overrides can be switched off', function () {
    $completed = makePcActivity(['phys_complete_pct' => '50'], 'TK_Complete');
    $notStarted = makePcActivity(['phys_complete_pct' => '30'], 'TK_NotStart');

    expect(PercentComplete::resolve($completed, statusOverrides: false))->toBe(50.0)
        ->and(PercentComplete::resolve($notStarted, statusOverrides: false))->toBe(30.0);
});

// ---------------------------------------------------------------
// complete_pct_type
// ---------------------------------------------------------------

test('CP_Drtn is duration progress, not the stale physical pct', function () {
    // 40h target, 24h remaining -> 40% duration-complete; physical says 0.
    $activity = makePcActivity(['phys_complete_pct' => '0', 'complete_pct_type' => 'CP_Drtn', 'target_drtn_hr_cnt' => '40', 'remain_drtn_hr_cnt' => '24']);

    expect(PercentComplete::resolve($activity))->toBe(40.0);
});

test('CP_Drtn falls back to the physical pct without a remaining duration or a target', function () {
    $noRemaining = makePcActivity(['phys_complete_pct' => '12', 'complete_pct_type' => 'CP_Drtn', 'target_drtn_hr_cnt' => '40']);
    $noTarget = makePcActivity(['phys_complete_pct' => '12', 'complete_pct_type' => 'CP_Drtn', 'target_drtn_hr_cnt' => '0', 'remain_drtn_hr_cnt' => '0']);

    expect(PercentComplete::resolve($noRemaining))->toBe(12.0)
        ->and(PercentComplete::resolve($noTarget))->toBe(12.0);
});

test('CP_Units is unit progress: actual over actual plus remaining', function () {
    // 30 actual + 70 remaining -> 30% units-complete.
    $activity = makePcActivity(['complete_pct_type' => 'CP_Units', 'act_work_qty' => '30', 'remain_work_qty' => '70']);

    expect(PercentComplete::resolve($activity))->toBe(30.0);
});

test('CP_Units falls back to the physical pct when there are no units', function () {
    $activity = makePcActivity(['phys_complete_pct' => '12', 'complete_pct_type' => 'CP_Units', 'act_work_qty' => '0', 'remain_work_qty' => '0']);

    expect(PercentComplete::resolve($activity))->toBe(12.0);
});

test('CP_Phys and unknown types use the physical pct', function () {
    $phys = makePcActivity(['phys_complete_pct' => '25', 'complete_pct_type' => 'CP_Phys', 'target_drtn_hr_cnt' => '40', 'remain_drtn_hr_cnt' => '24']);
    $unknown = makePcActivity(['phys_complete_pct' => '25', 'complete_pct_type' => 'CP_Other', 'target_drtn_hr_cnt' => '40', 'remain_drtn_hr_cnt' => '24']);

    expect(PercentComplete::resolve($phys))->toBe(25.0)
        ->and(PercentComplete::resolve($unknown))->toBe(25.0);
});

test('the complete_pct_type can be ignored', function () {
    $activity = makePcActivity(['phys_complete_pct' => '10', 'complete_pct_type' => 'CP_Drtn', 'target_drtn_hr_cnt' => '40', 'remain_drtn_hr_cnt' => '24']);

    expect(PercentComplete::resolve($activity, respectType: false))->toBe(10.0);
});

test('the status override still applies when the type is ignored', function () {
    $activity = makePcActivity(['phys_complete_pct' => '50', 'complete_pct_type' => 'CP_Drtn', 'target_drtn_hr_cnt' => '40', 'remain_drtn_hr_cnt' => '24'], 'TK_Complete');

    expect(PercentComplete::resolve($activity, respectType: false))->toBe(100.0);
});

// ---------------------------------------------------------------
// Physical -> activity percent chain
// ---------------------------------------------------------------

test('the physical pct wins over the activity pct', function () {
    expect(PercentComplete::resolve(makePcActivity(['phys_complete_pct' => '75', 'complete_pct' => '25'])))->toBe(75.0);
});

test('the activity pct is used when there is no physical pct', function () {
    expect(PercentComplete::resolve(makePcActivity(['complete_pct' => '25'])))->toBe(25.0);
});

test('an activity with no percent fields is 0', function () {
    expect(PercentComplete::resolve(makePcActivity()))->toBe(0.0);
});

// ---------------------------------------------------------------
// Clamping
// ---------------------------------------------------------------

test('percent complete clamps to the 0 to 100 range', function () {
    $over = makePcActivity(['phys_complete_pct' => '250']);
    $under = makePcActivity(['phys_complete_pct' => '-5']);
    // More remaining than the target: duration progress would be negative.
    $overrun = makePcActivity(['complete_pct_type' => 'CP_Drtn', 'target_drtn_hr_cnt' => '40', 'remain_drtn_hr_cnt' => '60']);

    expect(PercentComplete::resolve($over))->toBe(100.0)
        ->and(PercentComplete::resolve($under))->toBe(0.0)
        ->and(PercentComplete::resolve($overrun))->toBe(0.0);
});
