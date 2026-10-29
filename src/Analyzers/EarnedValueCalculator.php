<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\DTOs\ActivityEvmInput;
use TeckFuture\P6Analyzer\Enums\EvmMethod;
use TeckFuture\P6Analyzer\Support\Config;
use TeckFuture\P6Analyzer\Support\PercentComplete;

/**
 * Calculates Earned Value Management (EVM) metrics.
 *
 * The measurement method (cost, labor units, or duration) applies to the
 * WHOLE calculation — per-activity unit mixing is never performed. Method-
 * specific field semantics live in the extract*() methods (intentionally
 * parallel, not abstracted); every formula downstream is shared.
 *
 * Options (constructor array, falling back to config('p6-analyzer.evm.*')):
 * - infer_actuals_from_remaining (bool, default true): when actuals are not
 *   tracked, derive AC = BAC − remaining for activities that carry a
 *   remaining amount. Absent remaining fields never trigger inference.
 * - respect_complete_pct_type (bool, default true): honor P6's per-activity
 *   complete_pct_type (CP_Phys / CP_Drtn / CP_Units) when picking the
 *   percent complete that earns value.
 * - status_overrides_percent (bool, default true): Completed activities earn
 *   100% and Not Started activities earn 0%, regardless of stale % fields.
 * - auto_coverage_threshold (float, default 0.5): minimum share of
 *   measurable activities that must carry data for Auto to pick a method.
 */
class EarnedValueCalculator
{
    /**
     * Summary/milestone task types excluded from EVM sums: WBS summary bars
     * duplicate their children's values; milestones carry no work.
     */
    private const EXCLUDED_TASK_TYPES = [ActivityData::WBS_SUMMARY_TASK_TYPE, ...ActivityData::MILESTONE_TASK_TYPES];

    /** @param array<string, mixed> $options Per-instance overrides of config('p6-analyzer.evm.*'). */
    public function __construct(private readonly array $options = []) {}

    /**
     * Calculate EVM metrics for one measurement method.
     *
     * @param  Collection<int, ActivityData>|array<int, ActivityData>  $activities
     * @return array{method: string, unit: string, data_date: ?string, planned_value: float, earned_value: float, actual_cost: float, budget_at_completion: float, cost_variance: float, schedule_variance: float, cpi: ?float, spi: ?float, estimate_at_completion: float, estimate_to_complete: float, variance_at_completion: float, tcpi: ?float, coverage: array{total_activities: int, measurable_activities: int, activities_with_data: int, coverage_pct: float}, actual_cost_sources: array{tracked: int, inferred_from_remaining: int, status: int, none: int}}
     */
    public function calculate(
        Collection|array $activities,
        ?string $dataDate = null,
        EvmMethod $method = EvmMethod::Auto,
    ): array {
        $activities = is_array($activities) ? collect($activities) : $activities;

        if ($method === EvmMethod::Auto) {
            $method = $this->resolveAutoMethod($activities);
        }

        return $this->computeTotals($activities, $method, $dataDate);
    }

    /**
     * Calculate EVM metrics under every measurement method in one pass,
     * mirroring P6's paired By-Cost / By-Labor-Units summaries.
     *
     * @param  Collection<int, ActivityData>|array<int, ActivityData>  $activities
     * @return array{data_date: ?string, by_cost: array, by_labor_units: array, by_duration: array, recommended: string}
     */
    public function report(Collection|array $activities, ?string $dataDate = null): array
    {
        $activities = is_array($activities) ? collect($activities) : $activities;

        return [
            'data_date' => $dataDate,
            'by_cost' => $this->computeTotals($activities, EvmMethod::Cost, $dataDate),
            'by_labor_units' => $this->computeTotals($activities, EvmMethod::LaborUnits, $dataDate),
            'by_duration' => $this->computeTotals($activities, EvmMethod::Duration, $dataDate),
            'recommended' => $this->resolveAutoMethod($activities)->value,
        ];
    }

    // ---------------------------------------------------------------
    // Shared calculation core
    // ---------------------------------------------------------------

    /**
     * @param  Collection<int, ActivityData>  $activities
     */
    private function computeTotals(Collection $activities, EvmMethod $method, ?string $dataDate): array
    {
        $currentDate = $dataDate ? Carbon::parse($dataDate) : null;
        $inferFromRemaining = (bool) $this->option('infer_actuals_from_remaining', true);

        $plannedValue = 0.0;
        $earnedValue = 0.0;
        $actualCost = 0.0;
        $budgetAtCompletion = 0.0;

        $measurable = 0;
        $withData = 0;
        $acSources = ['tracked' => 0, 'inferred_from_remaining' => 0, 'status' => 0, 'none' => 0];

        foreach ($activities as $activity) {
            if (in_array($activity->task_type, self::EXCLUDED_TASK_TYPES, true)) {
                continue;
            }
            $measurable++;

            $input = $this->extractInput($activity, $method);
            if ($input === null) {
                continue;
            }
            $withData++;

            // AC resolution: tracked wins; otherwise infer from a PRESENT
            // remaining amount (0.0 remaining means fully spent — only an
            // absent field blocks inference); otherwise fall back to status.
            if ($input->trackedActual > 0) {
                $ac = $input->trackedActual;
                $acSources['tracked']++;
            } elseif ($inferFromRemaining && $input->remaining !== null) {
                $ac = min($input->bac, max(0.0, $input->bac - $input->remaining));
                $acSources['inferred_from_remaining']++;
            } elseif ($activity->status_code === 'Completed') {
                $ac = $input->bac;
                $acSources['status']++;
            } else {
                $ac = 0.0;
                $acSources['none']++;
            }

            $pv = $input->explicitPv;
            if ($pv === null) {
                $pv = ($currentDate && $activity->target_start_date && $activity->target_end_date)
                    ? $this->estimatePlannedValue($activity, $input->bac, $currentDate)
                    : 0.0;
            }

            $budgetAtCompletion += $input->bac;
            $actualCost += $ac;
            $earnedValue += $input->bac * ($input->pctComplete / 100);
            $plannedValue += $pv;
        }

        // Indices from unrounded sums; null (not a fake 1.0) when undefined.
        $cpi = $actualCost > 0 ? $earnedValue / $actualCost : null;
        $spi = $plannedValue > 0 ? $earnedValue / $plannedValue : null;

        $eac = ($cpi !== null && $cpi > 0) ? $budgetAtCompletion / $cpi : $budgetAtCompletion;
        $etc = $eac - $actualCost;
        $vac = $budgetAtCompletion - $eac;
        $remainingBudget = $budgetAtCompletion - $actualCost;
        $tcpi = $remainingBudget > 0
            ? ($budgetAtCompletion - $earnedValue) / $remainingBudget
            : null;

        return [
            'method' => $method->value,
            'unit' => $method->unit(),
            'data_date' => $dataDate,
            'planned_value' => round($plannedValue, 2),
            'earned_value' => round($earnedValue, 2),
            'actual_cost' => round($actualCost, 2),
            'budget_at_completion' => round($budgetAtCompletion, 2),
            'cost_variance' => round($earnedValue - $actualCost, 2),
            'schedule_variance' => round($earnedValue - $plannedValue, 2),
            'cpi' => $cpi !== null ? round($cpi, 2) : null,
            'spi' => $spi !== null ? round($spi, 2) : null,
            'estimate_at_completion' => round($eac, 2),
            'estimate_to_complete' => round($etc, 2),
            'variance_at_completion' => round($vac, 2),
            'tcpi' => $tcpi !== null ? round($tcpi, 2) : null,
            'coverage' => [
                'total_activities' => $activities->count(),
                'measurable_activities' => $measurable,
                'activities_with_data' => $withData,
                'coverage_pct' => $measurable > 0 ? round($withData / $measurable, 2) : 0.0,
            ],
            'actual_cost_sources' => $acSources,
        ];
    }

    // ---------------------------------------------------------------
    // Method-specific extraction (deliberately parallel, not abstracted:
    // each method's field semantics differ and should read independently)
    // ---------------------------------------------------------------

    private function extractInput(ActivityData $activity, EvmMethod $method): ?ActivityEvmInput
    {
        return match ($method) {
            EvmMethod::Cost => $this->extractCost($activity),
            EvmMethod::LaborUnits => $this->extractLaborUnits($activity),
            EvmMethod::Duration => $this->extractDuration($activity),
            EvmMethod::Auto => throw new \LogicException('Auto must be resolved before extraction.'),
        };
    }

    private function extractCost(ActivityData $activity): ?ActivityEvmInput
    {
        $bac = (float) ($activity->target_cost ?? 0);
        if ($bac <= 0) {
            return null;
        }

        return new ActivityEvmInput(
            bac: $bac,
            trackedActual: (float) ($activity->act_cost ?? 0),
            remaining: $activity->remain_cost,
            pctComplete: $this->resolvePercentComplete($activity),
            explicitPv: $activity->planned_value,
        );
    }

    private function extractLaborUnits(ActivityData $activity): ?ActivityEvmInput
    {
        $bac = (float) ($activity->target_work_qty ?? 0);
        if ($bac <= 0) {
            return null;
        }

        return new ActivityEvmInput(
            bac: $bac,
            trackedActual: (float) ($activity->act_work_qty ?? 0),
            remaining: $activity->remain_work_qty,
            pctComplete: $this->resolvePercentComplete($activity),
            explicitPv: null, // planned_value is a cost figure; never reuse it for hours.
        );
    }

    private function extractDuration(ActivityData $activity): ?ActivityEvmInput
    {
        $bac = (float) ($activity->target_drtn_hr_cnt ?? 0);
        if ($bac <= 0) {
            return null;
        }

        return new ActivityEvmInput(
            bac: $bac,
            trackedActual: 0.0, // P6 has no "actual duration spent" field; rely on remaining/status.
            remaining: $activity->remain_drtn_hr_cnt,
            pctComplete: $this->resolvePercentComplete($activity),
            explicitPv: null,
        );
    }

    // ---------------------------------------------------------------
    // Percent complete & method selection
    // ---------------------------------------------------------------

    /**
     * Pick the percent complete (0–100) that earns value, honoring the
     * activity's P6 complete_pct_type and status when configured.
     */
    private function resolvePercentComplete(ActivityData $activity): float
    {
        return PercentComplete::resolve(
            $activity,
            statusOverrides: (bool) $this->option('status_overrides_percent', true),
            respectType: (bool) $this->option('respect_complete_pct_type', true),
        );
    }

    /**
     * Resolve Auto: prefer cost, then labor units, when enough measurable
     * activities carry that data; duration is the last resort.
     *
     * @param  Collection<int, ActivityData>  $activities
     */
    private function resolveAutoMethod(Collection $activities): EvmMethod
    {
        $threshold = (float) $this->option('auto_coverage_threshold', 0.5);

        foreach ([EvmMethod::Cost, EvmMethod::LaborUnits] as $method) {
            if ($this->methodCoverage($activities, $method) >= $threshold) {
                return $method;
            }
        }

        return EvmMethod::Duration;
    }

    /**
     * Share of measurable (non-summary, non-milestone) activities carrying
     * data for the given method.
     *
     * @param  Collection<int, ActivityData>  $activities
     */
    private function methodCoverage(Collection $activities, EvmMethod $method): float
    {
        $measurable = 0;
        $withData = 0;

        foreach ($activities as $activity) {
            if (in_array($activity->task_type, self::EXCLUDED_TASK_TYPES, true)) {
                continue;
            }
            $measurable++;
            if ($this->extractInput($activity, $method) !== null) {
                $withData++;
            }
        }

        return $measurable > 0 ? $withData / $measurable : 0.0;
    }

    // ---------------------------------------------------------------
    // Planned value estimation
    // ---------------------------------------------------------------

    /**
     * Time-phase BAC linearly between the target dates relative to the data
     * date. An approximation (calendar time, no resource curves) — but a
     * real time-phased PV, unlike equating PV with BAC.
     */
    private function estimatePlannedValue(ActivityData $activity, float $bac, Carbon $currentDate): float
    {
        $targetStart = $activity->target_start_date;
        $targetEnd = $activity->target_end_date;

        if ($currentDate->lessThanOrEqualTo($targetStart)) {
            $schedulePct = 0.0;
        } elseif ($currentDate->greaterThanOrEqualTo($targetEnd)) {
            $schedulePct = 100.0;
        } else {
            $totalSeconds = $targetEnd->timestamp - $targetStart->timestamp;
            $elapsedSeconds = $currentDate->timestamp - $targetStart->timestamp;
            $schedulePct = $totalSeconds > 0 ? ($elapsedSeconds / $totalSeconds) * 100 : 100.0;
        }

        return $bac * ($schedulePct / 100);
    }

    /**
     * Read an option with config fallback for non-Laravel environments.
     */
    private function option(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->options)) {
            return $this->options[$key];
        }

        return Config::get("evm.{$key}", $default);
    }
}
