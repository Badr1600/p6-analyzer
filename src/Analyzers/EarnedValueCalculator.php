<?php

namespace TeckFuture\P6Analyzer\Analyzers;

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
    public function calculate(Collection|array $activities): array
    {
        $activities = is_array($activities) ? collect($activities) : $activities;
        $plannedValue = 0.0;
        $earnedValue = 0.0;
        $actualCost = 0.0;
        $budgetAtCompletion = 0.0;

        foreach ($activities as $activity) {
            $bac = (float) ($activity->target_cost ?? 0);
            $ac = (float) ($activity->act_cost ?? 0);

            $budgetAtCompletion += $bac;
            $actualCost += $ac;

            $pctComplete = (float) ($activity->phys_comp_pct ?? ($activity->complete_pct ?? 0));
            $ev = $bac * ($pctComplete / 100);
            $earnedValue += $ev;

            $pv = (float) ($activity->planned_value ?? 0);
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
