<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Carbon\Carbon;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;

/**
 * Generates a filtered x-week lookahead schedule.
 */
class LookaheadGenerator
{
    /**
     * @param  string  $dataDate  Valid date string.
     * @param  int  $weeks  Number of weeks to look ahead (default 3).
     * @return array{data_date: string, lookahead_weeks: int, end_date: string, activity_count: int, activities: array}
     */
    public function generate(ParsedXerData $data, string $dataDate, int $weeks = 3): array
    {
        $activities = $data->getActivities();
        $startDate = Carbon::parse($dataDate);
        $endDate = $startDate->copy()->addWeeks($weeks);

        $lookahead = [];

        foreach ($activities as $activity) {
            $status = $activity->status_code;
            if ($status === 'Completed') {
                continue;
            }

            $start = $activity->target_start_date ?? $activity->act_start_date;
            $finish = $activity->target_end_date ?? $activity->act_end_date;

            $startsInWindow = $start && $start->between($startDate, $endDate);
            $finishesInWindow = $finish && $finish->between($startDate, $endDate);
            $spansWindow = $start && $finish && $start->isBefore($startDate) && $finish->isAfter($endDate);

            if ($startsInWindow || $finishesInWindow || $spansWindow) {
                $lookahead[] = [
                    'id' => $activity->id,
                    'task_code' => $activity->task_code,
                    'name' => $activity->name,
                    'start_date' => $start?->toDateString(),
                    'finish_date' => $finish?->toDateString(),
                    'status' => $status,
                    'total_float' => $activity->total_float_hr_cnt,
                ];
            }
        }

        usort($lookahead, function ($a, $b) {
            if (! $a['start_date']) {
                return 1;
            }
            if (! $b['start_date']) {
                return -1;
            }

            return strcmp($a['start_date'], $b['start_date']);
        });

        return [
            'data_date' => $startDate->toDateString(),
            'lookahead_weeks' => $weeks,
            'end_date' => $endDate->toDateString(),
            'activity_count' => count($lookahead),
            'activities' => $lookahead,
        ];
    }
}
