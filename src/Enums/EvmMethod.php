<?php

namespace TeckFuture\P6Analyzer\Enums;

/**
 * The measurement basis used for Earned Value Management calculations.
 *
 * A single method is applied to the ENTIRE calculation — units are never
 * mixed across activities (summing dollars and hours produces meaningless
 * totals). Activities lacking data for the chosen method contribute nothing
 * and are reported in the result's coverage block instead.
 */
enum EvmMethod: string
{
    /**
     * Monetary basis: BAC/AC/remaining from resource-assignment costs
     * (TASKRSRC target_cost / act_reg_cost + act_ot_cost / remain_cost).
     */
    case Cost = 'cost';

    /**
     * Labor-unit basis: BAC/AC/remaining from the TASK table's native
     * work quantities (target_work_qty / act_work_qty / remain_work_qty).
     * Mirrors P6's own "By Labor Units" summary metrics.
     */
    case LaborUnits = 'labor_units';

    /**
     * Duration basis: BAC/remaining from activity durations
     * (target_drtn_hr_cnt / remain_drtn_hr_cnt). A last-resort proxy for
     * schedules that carry neither costs nor labor units.
     */
    case Duration = 'duration';

    /**
     * Resolve the best-covered method for the whole project at calculation
     * time. Preference order: Cost, then LaborUnits (when coverage meets the
     * configured threshold), then Duration. The resolved method is reported
     * in the result — Auto itself never appears in output.
     */
    case Auto = 'auto';

    /**
     * The unit label for values produced under this method.
     */
    public function unit(): string
    {
        return match ($this) {
            self::Cost => 'currency',
            self::LaborUnits => 'labor_hours',
            self::Duration => 'schedule_hours',
            self::Auto => 'mixed', // Never emitted; Auto resolves before output.
        };
    }

    /**
     * Coerce a string (e.g. from config or request input) into a method.
     */
    public static function fromString(self|string|null $value, self $default = self::Auto): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return self::tryFrom($value) ?? $default;
    }
}
