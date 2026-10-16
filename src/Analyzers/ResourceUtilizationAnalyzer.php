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
     * @param  int|null  $weekStartsOn  The day the 'week' period starts on: Carbon's day number, 0 (Sunday) to 6
     *                                  (Saturday). Only the 'week' period uses it; 'day' and 'month' ignore it. Left null (the default), weeks
     *                                  start on the Carbon locale's first day (Monday for 'en') — the pre-2.3.0 behaviour, unchanged.
     * @param  array<int|string, string>  $roleLabels  Display labels for role-only lines, keyed by role_id. A role without
     *                                                 an entry is labelled with ROLES.role_short_name, then 'Role {id}'.
     * @return array{utilization: array, over_allocations: array, total_resources_analyzed: int, roles: array<string, array{kind: string, role_id: string, label: string}>}
     *
     * A TASKRSRC line with no rsrc_id but a role_id is counted by role: it lands in a bucket keyed 'role:{role_id}'
     * (which cannot collide with a resource name), needs no RSRC row, and is described in the 'roles' map.
     * Over-allocations of a role carry the bucket key in 'resource', plus 'kind' => 'role' and the 'label'.
     */
    public function calculate(ParsedXerData $data, string $period = 'week', ?float $dailyLimit = null, ?int $weekStartsOn = null, array $roleLabels = []): array
    {
        if ($weekStartsOn !== null && ($weekStartsOn < 0 || $weekStartsOn > 6)) {
            throw new \InvalidArgumentException('weekStartsOn must be a day number from 0 (Sunday) to 6 (Saturday).');
        }

        $dailyLimit = $dailyLimit ?? $this->configValue('resource_overallocation_daily_limit', 8);
        $activities = $data->getActivities()->keyBy('id');
        $assignments = $data->getTable('TASKRSRC');
        $resources = $data->getTable('RSRC')->keyBy('rsrc_id');
        $roles = $data->getTable('ROLES')->keyBy('role_id');

        $utilization = [];
        $overAllocations = [];
        $roleBuckets = [];

        foreach ($assignments as $assignment) {
            $taskId = $assignment['task_id'] ?? null;
            $rsrcId = $assignment['rsrc_id'] ?? null;
            $roleId = $assignment['role_id'] ?? null;
            $isRoleLine = ! $rsrcId && $roleId;

            if (! $taskId || (! $rsrcId && ! $roleId)) {
                continue;
            }

            $activity = $activities->get($taskId);

            if (! $activity) {
                continue;
            }

            if ($isRoleLine) {
                $rsrcName = 'role:'.$roleId;
            } else {
                $resource = $resources->get($rsrcId);

                if (! $resource) {
                    continue;
                }

                $rsrcName = $resource['rsrc_name'] ?? $rsrcId;
            }
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

            if ($isRoleLine && ! isset($roleBuckets[$rsrcName])) {
                $roleBuckets[$rsrcName] = [
                    'kind' => 'role',
                    'role_id' => (string) $roleId,
                    'label' => $this->roleLabel($roleId, $roleLabels, $roles->get($roleId)),
                ];
            }

            $current = $start->copy();
            while ($current->lessThanOrEqualTo($end)) {
                $periodKey = $this->getPeriodKey($current, $period, $weekStartsOn);

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
                    ] + ($isRoleLine ? ['kind' => 'role', 'label' => $roleBuckets[$rsrcName]['label']] : []);
                }

                $current->addDay();
            }
        }

        return [
            'utilization' => $utilization,
            'over_allocations' => $overAllocations,
            'total_resources_analyzed' => count($utilization),
            'roles' => $roleBuckets,
        ];
    }

    /**
     * @param  array<int|string, string>  $roleLabels
     * @param  array<string, string>|null  $role  The ROLES row, when the table has one.
     */
    private function roleLabel(string $roleId, array $roleLabels, ?array $role): string
    {
        foreach ([$roleLabels[$roleId] ?? null, $role['role_short_name'] ?? null] as $label) {
            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        return 'Role '.$roleId;
    }

    /**
     * Get the period key for grouping. Uses copy() to avoid mutating the original Carbon instance.
     */
    private function getPeriodKey(Carbon $date, string $period, ?int $weekStartsOn): string
    {
        return match ($period) {
            'day' => $date->toDateString(),
            'month' => $date->format('Y-m'),
            default => $weekStartsOn === null
                ? $date->copy()->startOfWeek()->toDateString().' (Week)'
                : $date->copy()->startOfWeek($weekStartsOn)->toDateString().' (Week)',
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
