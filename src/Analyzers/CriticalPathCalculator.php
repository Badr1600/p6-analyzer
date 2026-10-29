<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\Support\Config;

/**
 * Identifies critical path activities based on total float.
 *
 * An activity is considered critical when its total float is at or below
 * the configured threshold (default: 0 hours).
 *
 * @return array{total_activities: int, critical_activities_count: int, critical_path_percentage: float, critical_activities: array}
 */
class CriticalPathCalculator
{
    /**
     * Calculate the critical path.
     *
     * @param  Collection<int, ActivityData>|array<int, ActivityData>  $activities
     * @param  float|null  $criticalFloatThreshold  Override the config threshold. Falls back to config, then 0.
     * @return array{total_activities: int, critical_activities_count: int, critical_path_percentage: float, critical_activities: array}
     */
    public function calculate(Collection|array $activities, ?float $criticalFloatThreshold = null): array
    {
        $activities = is_array($activities) ? collect($activities) : $activities;
        $threshold = $criticalFloatThreshold ?? Config::get('critical_float_threshold', 0);
        $criticalActivities = collect();
        $totalActivities = $activities->count();

        foreach ($activities as $activity) {
            $floatHr = $activity->total_float_hr_cnt;

            if ($floatHr !== null && (float) $floatHr <= $threshold) {
                $criticalActivities->push([
                    'id' => $activity->id,
                    'task_code' => $activity->task_code,
                    'name' => $activity->name,
                    'total_float_hr' => (float) $floatHr,
                ]);
            }
        }

        return [
            'total_activities' => $totalActivities,
            'critical_activities_count' => $criticalActivities->count(),
            'critical_path_percentage' => $totalActivities > 0 ? round(($criticalActivities->count() / $totalActivities) * 100, 2) : 0,
            'critical_activities' => $criticalActivities->values()->toArray(),
        ];
    }
}
