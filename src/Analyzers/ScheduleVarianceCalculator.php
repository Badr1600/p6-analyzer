<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ActivityData;

/**
 * Calculates schedule variance (slippage) between two sets of activities.
 *
 * Activities are matched by task_code — the stable business identifier that
 * survives re-imports. (Matching previously used activity_id, which imported
 * XER data never carries: keyBy(null) collapsed the whole baseline onto one
 * arbitrary row and every activity was compared against it.)
 */
class ScheduleVarianceCalculator
{
    /**
     * @param  Collection<int, ActivityData>|array<int, ActivityData>  $currentActivities
     * @param  Collection<int, ActivityData>|array<int, ActivityData>  $baselineActivities
     * @param  float|null  $minSlipDays  Only finish slips strictly greater than this
     *                                   many days count as slipping. Falls back to
     *                                   config p6-analyzer.min_slip_days, then 0.
     * @return array{
     *     total_activities_compared: int,
     *     current_activities_count: int,
     *     matched_count: int,
     *     unmatched_count: int,
     *     slipping_activities_count: int,
     *     average_variance_days: float,
     *     max_variance_days: int,
     *     slipping_activities: array<int, array<string, mixed>>
     * }
     */
    public function calculate(
        Collection|array $currentActivities,
        Collection|array $baselineActivities,
        ?float $minSlipDays = null
    ): array {
        $currentActivities = is_array($currentActivities) ? collect($currentActivities) : $currentActivities;
        $baselineActivities = is_array($baselineActivities) ? collect($baselineActivities) : $baselineActivities;
        $threshold = $minSlipDays ?? (float) $this->configValue('min_slip_days', 0);

        $baselineByCode = $baselineActivities
            ->filter(fn (ActivityData $a) => $a->task_code !== null && $a->task_code !== '')
            ->keyBy('task_code');

        $slippingActivities = collect();
        $matched = 0;

        $totalVarianceDays = 0;
        $maxVarianceDays = 0;

        foreach ($currentActivities as $current) {
            $baseline = $current->task_code ? $baselineByCode->get($current->task_code) : null;
            if (! $baseline) {
                continue;
            }
            $matched++;

            $currentFinish = $current->target_end_date;
            $baselineFinish = $baseline->target_end_date;
            $finishVariance = $this->signedDayDiff($baselineFinish, $currentFinish);

            if ($finishVariance === null || $finishVariance <= $threshold) {
                continue;
            }

            $totalVarianceDays += $finishVariance;
            if ($finishVariance > $maxVarianceDays) {
                $maxVarianceDays = $finishVariance;
            }

            $slippingActivities->push([
                'id' => $current->id,
                'task_code' => $current->task_code,
                'name' => $current->name,
                'status' => $current->status_code,
                'total_float_hr' => $current->total_float_hr_cnt,
                'master_start' => $baseline->target_start_date?->toDateString(),
                'workbench_start' => $current->target_start_date?->toDateString(),
                'start_variance_days' => $this->signedDayDiff($baseline->target_start_date, $current->target_start_date),
                'master_finish' => $baselineFinish?->toDateString(),
                'workbench_finish' => $currentFinish?->toDateString(),
                'variance_days' => $finishVariance,
            ]);
        }

        return [
            // Matched pairs — the figure "Activities Compared" implies. The
            // raw current-set size travels separately.
            'total_activities_compared' => $matched,
            'current_activities_count' => $currentActivities->count(),
            'matched_count' => $matched,
            'unmatched_count' => $currentActivities->count() - $matched,
            'slipping_activities_count' => $slippingActivities->count(),
            'average_variance_days' => $slippingActivities->count() > 0 ? round($totalVarianceDays / $slippingActivities->count(), 1) : 0,
            'max_variance_days' => $maxVarianceDays,
            'slipping_activities' => $slippingActivities->values()->toArray(),
        ];
    }

    /**
     * Whole days from $from to $to, negative when $to is earlier. Explicit
     * sign handling keeps the result stable across Carbon major versions.
     */
    private function signedDayDiff(?Carbon $from, ?Carbon $to): ?int
    {
        if ($from === null || $to === null) {
            return null;
        }

        $days = (int) round(abs($from->diffInDays($to)));

        return $to->lessThan($from) ? -$days : $days;
    }

    private function configValue(string $key, mixed $default): mixed
    {
        if (function_exists('config')) {
            return config("p6-analyzer.{$key}", $default);
        }

        return $default;
    }
}
