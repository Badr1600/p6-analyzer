<?php

namespace TeckFuture\P6Analyzer\Support;

use TeckFuture\P6Analyzer\DTOs\ActivityData;

/**
 * Shared rule for an activity's percent complete (0–100), so every analyzer
 * that weights by progress reads the same figure from the same P6 fields.
 *
 * Resolution order:
 * 1. Status wins: a Completed activity is 100% and a Not Started one 0%,
 *    whatever stale percent fields it still carries.
 * 2. P6's complete_pct_type picks the measure: CP_Drtn is duration progress
 *    ((target − remaining) / target) and CP_Units is unit progress
 *    (actual / (actual + remaining)). When the fields that measure needs are
 *    missing, or the type is CP_Phys or unknown, resolution falls through.
 * 3. The physical percent complete, then the activity's complete_pct, then 0.
 *
 * The result is clamped to 0–100.
 */
final class PercentComplete
{
    /**
     * @param  bool  $statusOverrides  Apply step 1.
     * @param  bool  $respectType  Apply step 2.
     */
    public static function resolve(ActivityData $activity, bool $statusOverrides = true, bool $respectType = true): float
    {
        if ($statusOverrides) {
            if ($activity->status_code === 'Completed') {
                return 100.0;
            }
            if ($activity->status_code === 'Not Started') {
                return 0.0;
            }
        }

        if ($respectType) {
            $typed = self::fromType($activity);
            if ($typed !== null) {
                return self::clamp($typed);
            }
        }

        return self::clamp((float) ($activity->phys_comp_pct ?? ($activity->complete_pct ?? 0)));
    }

    /**
     * Percent complete per P6's declared type; null falls back to the
     * physical → activity chain (covers CP_Phys and absent/unknown types).
     */
    private static function fromType(ActivityData $activity): ?float
    {
        if ($activity->complete_pct_type === 'CP_Drtn') {
            $target = (float) ($activity->target_drtn_hr_cnt ?? 0);
            if ($target > 0 && $activity->remain_drtn_hr_cnt !== null) {
                return (($target - $activity->remain_drtn_hr_cnt) / $target) * 100;
            }
        }

        if ($activity->complete_pct_type === 'CP_Units') {
            $actual = (float) ($activity->act_work_qty ?? 0);
            $atCompletion = $actual + (float) ($activity->remain_work_qty ?? 0);
            if ($atCompletion > 0) {
                return ($actual / $atCompletion) * 100;
            }
        }

        return null;
    }

    private static function clamp(float $pct): float
    {
        return min(100.0, max(0.0, $pct));
    }
}
