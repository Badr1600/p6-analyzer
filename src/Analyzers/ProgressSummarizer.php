<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;

/**
 * Generates a high-level progress summary of the schedule.
 */
class ProgressSummarizer
{
    /**
     * @param  ParsedXerData|Collection<int, ActivityData>|array<int, ActivityData>  $data
     * @return array{total_activities: int, status_counts: array, status_percentages: array, overall_percent_complete_by_duration: float}
     */
    public function summarize(ParsedXerData|Collection|array $data): array
    {
        $activities = match (true) {
            $data instanceof ParsedXerData => $data->getActivities(),
            is_array($data) => collect($data),
            default => $data,
        };

        $totalActivities = $activities->count();
        $notStarted = 0;
        $inProgress = 0;
        $completed = 0;

        $totalDuration = 0;
        $completedDuration = 0;

        foreach ($activities as $activity) {
            $status = $activity->status_code;
            if ($status === 'Completed') {
                $completed++;
            } elseif ($status === 'In Progress') {
                $inProgress++;
            } else {
                $notStarted++;
            }

            $duration = (float) ($activity->target_drtn_hr_cnt ?? 0);
            $totalDuration += $duration;

            $pctComplete = (float) ($activity->phys_comp_pct ?? ($activity->complete_pct ?? 0));
            $completedDuration += $duration * ($pctComplete / 100);
        }

        $overallPctComplete = $totalDuration > 0 ? round(($completedDuration / $totalDuration) * 100, 2) : 0;

        return [
            'total_activities' => $totalActivities,
            'status_counts' => [
                'not_started' => $notStarted,
                'in_progress' => $inProgress,
                'completed' => $completed,
            ],
            'status_percentages' => [
                'not_started' => $totalActivities > 0 ? round(($notStarted / $totalActivities) * 100, 2) : 0,
                'in_progress' => $totalActivities > 0 ? round(($inProgress / $totalActivities) * 100, 2) : 0,
                'completed' => $totalActivities > 0 ? round(($completed / $totalActivities) * 100, 2) : 0,
            ],
            'overall_percent_complete_by_duration' => $overallPctComplete,
        ];
    }
}
