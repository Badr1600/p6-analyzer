<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ActivityData;

/**
 * Calculates schedule variance (slippage) between two sets of activities.
 */
class ScheduleVarianceCalculator
{
    /**
     * @param  Collection<int, ActivityData>|array<int, ActivityData>  $workbenchActivities
     * @param  Collection<int, ActivityData>|array<int, ActivityData>  $masterActivities
     * @return array{total_activities_compared: int, slipping_activities_count: int, average_variance_days: float, max_variance_days: int, slipping_activities: array}
     */
    public function calculate(Collection|array $workbenchActivities, Collection|array $masterActivities): array
    {
        $workbenchActivities = is_array($workbenchActivities) ? collect($workbenchActivities) : $workbenchActivities;
        $masterActivities = is_array($masterActivities) ? collect($masterActivities) : $masterActivities;

        $slippingActivities = collect();
        $masterDict = $masterActivities->keyBy('activity_id');

        $totalVarianceDays = 0;
        $maxVarianceDays = 0;

        foreach ($workbenchActivities as $wbActivity) {
            $masterActivity = $masterDict->get($wbActivity->activity_id);
            if (! $masterActivity) {
                continue;
            }

            $wbFinish = $wbActivity->target_end_date;
            $masterFinish = $masterActivity->target_end_date;

            if ($wbFinish && $masterFinish && $wbFinish->isAfter($masterFinish)) {
                $varianceDays = $masterFinish->diffInDays($wbFinish);

                $totalVarianceDays += $varianceDays;
                if ($varianceDays > $maxVarianceDays) {
                    $maxVarianceDays = $varianceDays;
                }

                $slippingActivities->push([
                    'id' => $wbActivity->id,
                    'task_code' => $wbActivity->task_code,
                    'name' => $wbActivity->name,
                    'master_finish' => $masterFinish->toDateString(),
                    'workbench_finish' => $wbFinish->toDateString(),
                    'variance_days' => $varianceDays,
                ]);
            }
        }

        return [
            'total_activities_compared' => $workbenchActivities->count(),
            'slipping_activities_count' => $slippingActivities->count(),
            'average_variance_days' => $slippingActivities->count() > 0 ? round($totalVarianceDays / $slippingActivities->count(), 1) : 0,
            'max_variance_days' => $maxVarianceDays,
            'slipping_activities' => $slippingActivities->values()->toArray(),
        ];
    }
}
