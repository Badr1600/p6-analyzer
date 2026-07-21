<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ActivityData;

/**
 * Calculates Earned Value Management (EVM) metrics.
 */
class EarnedValueCalculator
{
    /**
     * @param  Collection<int, ActivityData>|array<int, ActivityData>  $activities
     * @return array{planned_value: float, earned_value: float, actual_cost: float, budget_at_completion: float, cost_variance: float, schedule_variance: float, cpi: float, spi: float, estimate_at_completion: float}
     */
    public function calculate(Collection|array $activities, ?string $dataDate = null): array
    {
        $activities = is_array($activities) ? collect($activities) : $activities;
        $plannedValue = 0.0;
        $earnedValue = 0.0;
        $actualCost = 0.0;
        $budgetAtCompletion = 0.0;

        $currentDate = $dataDate ? Carbon::parse($dataDate) : null;

        foreach ($activities as $activity) {
            $isDurationFallback = false;
            $bac = (float) ($activity->target_cost ?? 0);

            // If cost is 0, fallback to duration (hours) for EVM calculations
            if ($bac === 0.0 && ($activity->target_drtn_hr_cnt ?? 0) > 0) {
                $bac = (float) $activity->target_drtn_hr_cnt;
                $isDurationFallback = true;
            }

            // Estimate Actual Cost if falling back to duration
            $ac = (float) ($activity->act_cost ?? 0);
            if ($isDurationFallback && $ac === 0.0) {
                // Approximate AC based on duration if we don't have actual cost
                if ($activity->status_code === 'Completed') {
                    $ac = $bac;
                } elseif ($activity->status_code === 'In Progress' && $activity->act_start_date && $currentDate) {
                    $elapsed = $activity->act_start_date->diffInHours($currentDate);
                    $ac = min($bac, $elapsed); // Cap at BAC as a rough estimate
                }
            }

            $budgetAtCompletion += $bac;
            $actualCost += $ac;

            $pctComplete = (float) ($activity->phys_comp_pct ?? ($activity->complete_pct ?? 0));
            $ev = $bac * ($pctComplete / 100);
            $earnedValue += $ev;

            $pv = (float) ($activity->planned_value ?? 0);

            // Estimate Planned Value if not explicitly provided
            if ($pv === 0.0 && $bac > 0 && $currentDate && $activity->target_start_date && $activity->target_end_date) {
                $targetStart = clone $activity->target_start_date;
                $targetEnd = clone $activity->target_end_date;

                if ($currentDate->lessThanOrEqualTo($targetStart)) {
                    $schedulePct = 0.0;
                } elseif ($currentDate->greaterThanOrEqualTo($targetEnd)) {
                    $schedulePct = 100.0;
                } else {
                    $totalSeconds = $targetEnd->timestamp - $targetStart->timestamp;
                    $elapsedSeconds = $currentDate->timestamp - $targetStart->timestamp;
                    $schedulePct = $totalSeconds > 0 ? ($elapsedSeconds / $totalSeconds) * 100 : 100.0;
                }

                $pv = $bac * ($schedulePct / 100);
            }

            $plannedValue += $pv;
        }

        $costVariance = $earnedValue - $actualCost;
        $scheduleVariance = $earnedValue - $plannedValue;

        $cpi = $actualCost > 0 ? round($earnedValue / $actualCost, 2) : 1.0;
        $spi = $plannedValue > 0 ? round($earnedValue / $plannedValue, 2) : 1.0;

        $estimateAtCompletion = $cpi > 0 ? $budgetAtCompletion / $cpi : $budgetAtCompletion;

        return [
            'planned_value' => round($plannedValue, 2),
            'earned_value' => round($earnedValue, 2),
            'actual_cost' => round($actualCost, 2),
            'budget_at_completion' => round($budgetAtCompletion, 2),
            'cost_variance' => round($costVariance, 2),
            'schedule_variance' => round($scheduleVariance, 2),
            'cpi' => $cpi,
            'spi' => $spi,
            'estimate_at_completion' => round($estimateAtCompletion, 2),
        ];
    }
}
