<?php

namespace TeckFuture\P6Analyzer\Support;

/**
 * Shared rule for deriving actual cost when a P6 schedule tracks remaining
 * cost but never records actuals (a common P6 configuration: physical %
 * complete drives remaining units down while actual cost stays 0).
 */
final class CostInference
{
    /**
     * Resolve an aggregate actual cost from planned/tracked/remaining totals.
     *
     * Tracked actuals always win. Otherwise, when a positive remaining total
     * exists, actuals are inferred as planned − remaining. A remaining total
     * of 0 at the AGGREGATE level is treated as "no data" rather than
     * "complete" — a whole project with zero remaining and zero actuals is
     * far more likely unloaded than finished. (Per-activity resolution inside
     * EarnedValueCalculator intentionally differs: there, a present-but-zero
     * remaining means the activity is fully spent.)
     */
    public static function inferActual(float $planned, float $tracked, ?float $remaining): float
    {
        if ($tracked > 0) {
            return $tracked;
        }

        if ($remaining !== null && $remaining > 0 && $planned > 0) {
            return round(max(0.0, $planned - $remaining), 2);
        }

        return $tracked;
    }
}
