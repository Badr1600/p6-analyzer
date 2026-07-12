<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ActivityData;

/**
 * Performs the DCMA 14-point schedule assessment.
 *
 * Checks for high float, negative float, high duration, and invalid dates,
 * then produces an overall quality score from 0 to 100.
 */
class DCMA14PointChecker
{
    /**
     * @param  Collection<int, ActivityData>|array<int, ActivityData>  $activities
     * @param  string|null  $dataDate  The project data date for date validation.
     * @return array{total_activities: int, incomplete_activities: int, negative_float_count: int, negative_float_pct: float, high_float_count: int, high_float_pct: float, high_duration_count: int, high_duration_pct: float, invalid_dates_count: int, overall_score: float}
     */
    public function calculate(Collection|array $activities, ?string $dataDate = null): array
    {
        $activities = is_array($activities) ? collect($activities) : $activities;
        $totalActivities = $activities->count();
        $incompleteActivities = 0;

        $negativeFloat = 0;
        $highFloat = 0;
        $invalidDates = 0;
        $highDuration = 0;

        $highFloatThreshold = $this->configValue('high_float_threshold', 352);
        $highDurationThreshold = $this->configValue('high_duration_threshold', 352);
        $penalties = $this->configValue('dcma_penalty_weights', [
            'high_float' => 1,
            'negative_float' => 2,
            'high_duration' => 1,
            'invalid_dates' => 10,
        ]);

        $dataDateObj = $dataDate ? Carbon::parse($dataDate) : null;

        foreach ($activities as $activity) {
            $status = $activity->status_code;
            if ($status !== 'Completed') {
                $incompleteActivities++;
            }

            $floatHr = $activity->total_float_hr_cnt;
            if ($floatHr !== null) {
                if ($floatHr < 0) {
                    $negativeFloat++;
                }
                if ($floatHr > $highFloatThreshold) {
                    $highFloat++;
                }
            }

            $durationHr = $activity->target_drtn_hr_cnt;
            if ($durationHr !== null && $durationHr > $highDurationThreshold) {
                $highDuration++;
            }

            if ($dataDateObj) {
                $actualStart = $activity->act_start_date;
                $targetStart = $activity->target_start_date;

                if ($actualStart && $actualStart->isAfter($dataDateObj)) {
                    $invalidDates++;
                }
                if ($targetStart && $status !== 'Completed' && $targetStart->isBefore($dataDateObj) && ! $actualStart) {
                    $invalidDates++;
                }
            }
        }

        $baseCount = $incompleteActivities > 0 ? $incompleteActivities : ($totalActivities > 0 ? $totalActivities : 1);

        $metrics = [
            'total_activities' => $totalActivities,
            'incomplete_activities' => $incompleteActivities,
            'negative_float_count' => $negativeFloat,
            'negative_float_pct' => round(($negativeFloat / $baseCount) * 100, 2),
            'high_float_count' => $highFloat,
            'high_float_pct' => round(($highFloat / $baseCount) * 100, 2),
            'high_duration_count' => $highDuration,
            'high_duration_pct' => round(($highDuration / $baseCount) * 100, 2),
            'invalid_dates_count' => $invalidDates,
        ];

        $penalty = 0;
        if ($metrics['high_float_pct'] > 5) {
            $penalty += ($metrics['high_float_pct'] - 5) * ($penalties['high_float'] ?? 1);
        }
        if ($metrics['negative_float_pct'] > 0) {
            $penalty += $metrics['negative_float_pct'] * ($penalties['negative_float'] ?? 2);
        }
        if ($metrics['high_duration_pct'] > 5) {
            $penalty += ($metrics['high_duration_pct'] - 5) * ($penalties['high_duration'] ?? 1);
        }
        if ($invalidDates > 0) {
            $penalty += $penalties['invalid_dates'] ?? 10;
        }

        $metrics['overall_score'] = max(0, min(100, round(100 - $penalty, 2)));

        return $metrics;
    }

    private function configValue(string $key, mixed $default): mixed
    {
        if (function_exists('config')) {
            return config("p6-analyzer.{$key}", $default);
        }

        return $default;
    }
}
