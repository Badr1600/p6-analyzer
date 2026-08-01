<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Carbon\Carbon;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;

/**
 * Calculates resource utilization over time and flags over-allocations.
 */
class ResourceUtilizationAnalyzer
{
    /**
     * @param  string  $period  'day', 'week', or 'month'
     * @param  float|null  $dailyLimit  Override the daily over-allocation limit (hours). Falls back to config, then 8.
     * @return array{utilization: array, over_allocations: array, total_resources_analyzed: int}
     */
    public function calculate(ParsedXerData $data, string $period = 'week', ?float $dailyLimit = null): array
    {
        $dailyLimit = $dailyLimit ?? $this->configValue('resource_overallocation_daily_limit', 8);
        $activities = $data->getActivities()->keyBy('id');
        $assignments = $data->getTable('TASKRSRC');
        $resources = $data->getTable('RSRC')->keyBy('rsrc_id');

        $utilization = [];
        $overAllocations = [];

        foreach ($assignments as $assignment) {
            $taskId = $assignment['task_id'] ?? null;
            $rsrcId = $assignment['rsrc_id'] ?? null;

            if (! $taskId || ! $rsrcId) {
                continue;
            }

            $activity = $activities->get($taskId);
            $resource = $resources->get($rsrcId);

            if (! $activity || ! $resource) {
                continue;
            }

            $rsrcName = $resource['rsrc_name'] ?? $rsrcId;
            $qty = (float) ($assignment['target_qty'] ?? 0);

            // The assignment's own dates are the authoritative window for the
            // resource's work; the activity dates are the fallback.
            $start = $this->parseDate($assignment['target_start_date'] ?? null)
                ?? $activity->target_start_date
                ?? $activity->act_start_date;
            $end = $this->parseDate($assignment['target_end_date'] ?? null)
                ?? $activity->target_end_date
                ?? $activity->act_end_date;

            if (! $start || ! $end || $start->isAfter($end)) {
                continue;
            }

            // Whole-day count. XER dates carry times, and Carbon 3 returns a
            // fractional diffInDays — feeding that into the divisor while the
            // loop iterates whole days silently under-allocated hours.
            $days = (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
            $qtyPerDay = $qty / $days;

            $current = $start->copy();
            while ($current->lessThanOrEqualTo($end)) {
                $periodKey = $this->getPeriodKey($current, $period);

                if (! isset($utilization[$rsrcName])) {
                    $utilization[$rsrcName] = [];
                }

                if (! isset($utilization[$rsrcName][$periodKey])) {
                    $utilization[$rsrcName][$periodKey] = 0.0;
                }

                $utilization[$rsrcName][$periodKey] += $qtyPerDay;

                if ($qtyPerDay > $dailyLimit) {
                    $overAllocations[] = [
                        'resource' => $rsrcName,
                        'date' => $current->toDateString(),
                        'activity' => $activity->name,
                        'qty_per_day' => round($qtyPerDay, 2),
                    ];
                }

                $current->addDay();
            }
        }

        return [
            'utilization' => $utilization,
            'over_allocations' => $overAllocations,
            'total_resources_analyzed' => count($utilization),
        ];
    }

    /**
     * Get the period key for grouping. Uses copy() to avoid mutating the original Carbon instance.
     */
    private function getPeriodKey(Carbon $date, string $period): string
    {
        return match ($period) {
            'day' => $date->toDateString(),
            'month' => $date->format('Y-m'),
            default => $date->copy()->startOfWeek()->toDateString().' (Week)',
        };
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function configValue(string $key, mixed $default): mixed
    {
        if (function_exists('config')) {
            return config("p6-analyzer.{$key}", $default);
        }

        return $default;
    }
}
