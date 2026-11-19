<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\DTOs\RelationshipData;
use TeckFuture\P6Analyzer\Support\Config;
use TeckFuture\P6Analyzer\Support\MissingLogic;

/**
 * Performs the DCMA 14-point schedule assessment.
 *
 * All 14 points are represented in the `points` table; ten are assessable
 * from an imported schedule (logic, leads, lags, relationship types, hard
 * constraints, high float, negative float, high duration, invalid dates,
 * missed activities). The remaining four (resource loading, critical path
 * test, CPLI, BEI) require an approved baseline or an intentional-delay
 * simulation and are reported as not assessable rather than fabricated.
 *
 * The activity points (5 to 9 and 11) count detail work: WBS summaries and
 * level of effort activities are passed over. Points 5 to 8 count the
 * activities that are not complete, and the relationship points (2 to 4) the
 * relationships into one; point 11 is measured against the activities due
 * by the data date.
 *
 * `overall_score` still derives from the original four checks (high float,
 * negative float, high duration, invalid dates) and their flat
 * count/percentage keys, which follow the points' counting.
 */
class DCMA14PointChecker
{
    /**
     * Constraint types that stop an activity moving later, against the
     * logic: mandatory start and finish, start on and finish on, and start
     * on or before and finish on or before (DCMA's SNLT and FNLT).
     */
    private const HARD_CONSTRAINT_TYPES = ['CS_MANDSTART', 'CS_MANDFIN', 'CS_MSO', 'CS_MEO', 'CS_MSOB', 'CS_MEOB'];

    /**
     * Task types whose dates, duration and float are rolled up from other
     * activities, which the activity points pass over.
     */
    private const ROLLED_UP_TASK_TYPES = [ActivityData::WBS_SUMMARY_TASK_TYPE, ActivityData::LEVEL_OF_EFFORT_TASK_TYPE];

    /**
     * @param  Collection<int, ActivityData>|array<int, ActivityData>  $activities
     * @param  string|null  $dataDate  The project data date for date validation.
     * @param  Collection<int, RelationshipData>|array<int, RelationshipData>|null  $relationships
     *                                                                                              When provided, the logic, leads, lags and relationship-type
     *                                                                                              points become assessable.
     * @return array<string, mixed>
     */
    public function calculate(Collection|array $activities, ?string $dataDate = null, Collection|array|null $relationships = null): array
    {
        $activities = is_array($activities) ? collect($activities) : $activities;
        $relationships = is_array($relationships) ? collect($relationships) : $relationships;
        $totalActivities = $activities->count();
        $incompleteActivities = 0;

        $negativeFloat = 0;
        $highFloat = 0;
        $invalidDates = 0;
        $highDuration = 0;
        $hardConstraints = 0;
        $missedActivities = 0;

        $highFloatThreshold = Config::get('high_float_threshold', 352);
        $highDurationThreshold = Config::get('high_duration_threshold', 352);
        // A zero, negative or non-numeric setting cannot turn hours into days: use the 8 hour default.
        $hoursPerDay = (float) Config::get('hours_per_day', 8);
        $hoursPerDay = $hoursPerDay > 0 ? $hoursPerDay : 8.0;
        $penalties = Config::get('dcma_penalty_weights', [
            'high_float' => 1,
            'negative_float' => 2,
            'high_duration' => 1,
            'invalid_dates' => 10,
        ]);

        $dataDateObj = $dataDate ? Carbon::parse($dataDate) : null;
        // A data date with a time is measured to the minute, as P6 does. One
        // without stands for its whole day: nothing dated that day is
        // behind it or ahead of it.
        $dataDateEnd = $dataDateObj === null || preg_match('/\d{1,2}:\d{2}/', (string) $dataDate) === 1
            ? $dataDateObj
            : $dataDateObj->copy()->endOfDay();

        // What the activity points count: detail work. A WBS summary and a
        // level of effort activity take their dates, duration and float
        // from other activities, so they would be counted twice.
        $examinedActivities = 0;
        $examinedIncomplete = 0;
        $dueActivities = 0;
        // The activities a relationship into which the relationship points
        // pass over: the completed and the rolled up.
        $passedOverIds = [];

        foreach ($activities as $activity) {
            $isComplete = $activity->status_code === 'Completed';
            $isRolledUp = in_array($activity->task_type, self::ROLLED_UP_TASK_TYPES, true);
            if (! $isComplete) {
                $incompleteActivities++;
            }
            if (($isComplete || $isRolledUp) && $activity->id !== '') {
                $passedOverIds[$activity->id] = true;
            }

            if ($isRolledUp) {
                continue;
            }

            $examinedActivities++;

            // Points 5 to 8 are about work still to do: a completed
            // activity's float, duration and constraint no longer drive
            // the schedule.
            if (! $isComplete) {
                $examinedIncomplete++;

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

                if (in_array($activity->raw_data['cstr_type'] ?? null, self::HARD_CONSTRAINT_TYPES, true)
                    || in_array($activity->raw_data['cstr_type2'] ?? null, self::HARD_CONSTRAINT_TYPES, true)) {
                    $hardConstraints++;
                }
            }

            if ($dataDateObj) {
                if ($this->hasInvalidDates($activity, $isComplete, $dataDateObj, $dataDateEnd)) {
                    $invalidDates++;
                }

                // Missed: due by the data date, and finished after its
                // planned finish or not at all. Measured against those due.
                $plannedFinish = $activity->target_end_date;
                if ($plannedFinish && $plannedFinish->lessThanOrEqualTo($dataDateEnd)) {
                    $dueActivities++;

                    $actualFinish = $activity->act_end_date;
                    if (! $isComplete || ($actualFinish && $actualFinish->copy()->startOfDay()->isAfter($plannedFinish->copy()->startOfDay()))) {
                        $missedActivities++;
                    }
                }
            }
        }

        // Relationship-based points.
        $totalRelationships = 0;
        $leads = 0;
        $lags = 0;
        $fsRelationships = 0;
        $missingLogic = 0;
        $logicBase = 0;

        if ($relationships !== null) {
            foreach ($relationships as $rel) {
                // A relationship into a completed activity is history: its
                // lag and type no longer move anything. One into a WBS
                // summary or a level of effort activity only stretches it
                // over its neighbours.
                if (isset($passedOverIds[(string) ($rel->raw_data['task_id'] ?? '')])) {
                    continue;
                }

                $totalRelationships++;

                $lag = $rel->lag_hr_cnt ?? 0;
                if ($lag < 0) {
                    $leads++;
                } elseif ($lag > 0) {
                    $lags++;
                }

                if (str_ends_with($rel->pred_type, 'FS')) {
                    $fsRelationships++;
                }
            }

            // Point 1 uses the same rule as the schedule health check, and is
            // measured against the activities that rule examines.
            $missingLogic = MissingLogic::find($activities, $relationships)->count();
            $logicBase = MissingLogic::inScope($activities)->count();
        }

        $baseCount = max($examinedIncomplete, 1);
        $relationshipBase = max($totalRelationships, 1);

        $pct = fn (int $count, int $base) => round(($count / max($base, 1)) * 100, 2);

        $metrics = [
            'assessable' => $totalActivities > 0,
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

        $hasRelationships = $relationships !== null;
        $hasDataDate = $dataDateObj !== null;
        $assessable = $totalActivities > 0;

        $point = function (
            int $id,
            string $key,
            string $name,
            string $thresholdLabel,
            bool $applicable,
            ?int $count,
            ?float $percentage,
            ?bool $passed,
            ?string $reason = null
        ) {
            return [
                'id' => $id,
                'key' => $key,
                'name' => $name,
                'threshold_label' => $thresholdLabel,
                'assessable' => $applicable,
                'count' => $count,
                'percentage' => $percentage,
                'passed' => $passed,
                'reason' => $reason,
            ];
        };

        $noActivities = 'No activities in scope.';
        $needsRelationships = 'Relationship data was not provided.';
        $needsDataDate = 'No data date available.';

        $metrics['points'] = [
            $point(1, 'missing_logic', 'Logic', '≤ 5% missing a predecessor or successor', $assessable && $hasRelationships,
                $hasRelationships ? $missingLogic : null,
                $hasRelationships ? $pct($missingLogic, $logicBase) : null,
                $assessable && $hasRelationships ? $pct($missingLogic, $logicBase) <= 5 : null,
                $assessable ? ($hasRelationships ? null : $needsRelationships) : $noActivities),
            $point(2, 'leads', 'Leads (negative lag)', '0 relationships', $assessable && $hasRelationships,
                $hasRelationships ? $leads : null,
                $hasRelationships ? $pct($leads, $relationshipBase) : null,
                $assessable && $hasRelationships ? $leads === 0 : null,
                $assessable ? ($hasRelationships ? null : $needsRelationships) : $noActivities),
            $point(3, 'lags', 'Lags (positive lag)', '≤ 5% of relationships', $assessable && $hasRelationships,
                $hasRelationships ? $lags : null,
                $hasRelationships ? $pct($lags, $relationshipBase) : null,
                $assessable && $hasRelationships ? $pct($lags, $relationshipBase) <= 5 : null,
                $assessable ? ($hasRelationships ? null : $needsRelationships) : $noActivities),
            $point(4, 'relationship_types', 'Relationship types', '≥ 90% Finish-to-Start', $assessable && $hasRelationships && $totalRelationships > 0,
                $hasRelationships ? $fsRelationships : null,
                $hasRelationships && $totalRelationships > 0 ? $pct($fsRelationships, $totalRelationships) : null,
                $assessable && $hasRelationships && $totalRelationships > 0 ? $pct($fsRelationships, $totalRelationships) >= 90 : null,
                $assessable ? ($hasRelationships ? ($totalRelationships > 0 ? null : 'No relationships in scope.') : $needsRelationships) : $noActivities),
            $point(5, 'hard_constraints', 'Hard constraints', '≤ 5% of incomplete activities', $assessable,
                $hardConstraints, $pct($hardConstraints, $baseCount),
                $assessable ? $pct($hardConstraints, $baseCount) <= 5 : null,
                $assessable ? null : $noActivities),
            $point(6, 'high_float', 'High float', '≤ 5% over '.round($highFloatThreshold / $hoursPerDay).' days', $assessable,
                $highFloat, $metrics['high_float_pct'],
                $assessable ? $metrics['high_float_pct'] <= 5 : null,
                $assessable ? null : $noActivities),
            $point(7, 'negative_float', 'Negative float', '0 activities', $assessable,
                $negativeFloat, $metrics['negative_float_pct'],
                $assessable ? $negativeFloat === 0 : null,
                $assessable ? null : $noActivities),
            $point(8, 'high_duration', 'High duration', '≤ 5% over '.round($highDurationThreshold / $hoursPerDay).' days', $assessable,
                $highDuration, $metrics['high_duration_pct'],
                $assessable ? $metrics['high_duration_pct'] <= 5 : null,
                $assessable ? null : $noActivities),
            $point(9, 'invalid_dates', 'Invalid dates', '0 activities', $assessable && $hasDataDate,
                $hasDataDate ? $invalidDates : null,
                $hasDataDate ? $pct($invalidDates, $examinedActivities) : null,
                $assessable && $hasDataDate ? $invalidDates === 0 : null,
                $assessable ? ($hasDataDate ? null : $needsDataDate) : $noActivities),
            $point(10, 'resources', 'Resources', 'All effort activities resourced', false,
                null, null, null, 'Requires a cost/resource loading assessment.'),
            $point(11, 'missed_activities', 'Missed activities', '≤ 5% of those due finished late or not at all', $assessable && $hasDataDate,
                $hasDataDate ? $missedActivities : null,
                $hasDataDate ? $pct($missedActivities, $dueActivities) : null,
                $assessable && $hasDataDate ? $pct($missedActivities, $dueActivities) <= 5 : null,
                $assessable ? ($hasDataDate ? null : $needsDataDate) : $noActivities),
            $point(12, 'critical_path_test', 'Critical path test', 'Delay propagates to finish', false,
                null, null, null, 'Requires an intentional-delay simulation in the scheduling tool.'),
            $point(13, 'cpli', 'Critical path length index', '≥ 0.95', false,
                null, null, null, 'Requires an approved baseline schedule.'),
            $point(14, 'bei', 'Baseline execution index', '≥ 0.95', false,
                null, null, null, 'Requires an approved baseline schedule.'),
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

    /**
     * Point 9: a date that cannot be right at the data date. An actual start
     * or finish after it has not happened yet. A start or finish still
     * forecast before it should have happened and has no actual: an
     * activity not started whose planned start is behind it, or one not
     * finished whose early finish (else its remaining finish) is behind it.
     * An activity with several such dates is counted once.
     *
     * @param  Carbon  $dataDate  The data date, which a forecast date must not be before.
     * @param  Carbon  $dataDateEnd  The same moment, or the end of its day when it has no time: what an actual
     *                               date must not be after.
     */
    private function hasInvalidDates(ActivityData $activity, bool $isComplete, Carbon $dataDate, Carbon $dataDateEnd): bool
    {
        if ($activity->act_start_date?->isAfter($dataDateEnd) || $activity->act_end_date?->isAfter($dataDateEnd)) {
            return true;
        }

        if ($isComplete) {
            return false;
        }

        if (! $activity->act_start_date && $activity->target_start_date?->isBefore($dataDate)) {
            return true;
        }

        $forecastFinish = $this->dateOf($activity->raw_data['early_end_date'] ?? null)
            ?? $this->dateOf($activity->raw_data['reend_date'] ?? null);

        return (bool) $forecastFinish?->isBefore($dataDate);
    }

    private function dateOf(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception) {
            return null;
        }
    }
}
