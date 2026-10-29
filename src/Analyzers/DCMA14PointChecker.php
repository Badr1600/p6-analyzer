<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\DTOs\RelationshipData;
use TeckFuture\P6Analyzer\Support\Config;

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
 * The flat count/percentage keys and `overall_score` keep their original
 * semantics (score derives from the original four checks) so stored score
 * history remains comparable across package versions.
 */
class DCMA14PointChecker
{
    /** Constraint types that pin an activity to a date against the logic. */
    private const HARD_CONSTRAINT_TYPES = ['CS_MANDSTART', 'CS_MANDFIN', 'CS_MSO', 'CS_MEO'];

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

        foreach ($activities as $activity) {
            $status = $activity->status_code;
            $isComplete = $status === 'Completed';
            if (! $isComplete) {
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

            $constraintType = $activity->raw_data['cstr_type'] ?? null;
            if (in_array($constraintType, self::HARD_CONSTRAINT_TYPES, true)) {
                $hardConstraints++;
            }

            if ($dataDateObj) {
                $actualStart = $activity->act_start_date;
                $targetStart = $activity->target_start_date;

                if ($actualStart && $actualStart->isAfter($dataDateObj)) {
                    $invalidDates++;
                }
                if ($targetStart && ! $isComplete && $targetStart->isBefore($dataDateObj) && ! $actualStart) {
                    $invalidDates++;
                }

                // Missed: still open although its planned finish is behind
                // the data date.
                if (! $isComplete && $activity->target_end_date && $activity->target_end_date->isBefore($dataDateObj)) {
                    $missedActivities++;
                }
            }
        }

        // Relationship-based points.
        $totalRelationships = 0;
        $leads = 0;
        $lags = 0;
        $fsRelationships = 0;
        $missingLogic = 0;

        if ($relationships !== null) {
            $hasPredecessor = [];
            $hasSuccessor = [];

            foreach ($relationships as $rel) {
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

                $successor = $rel->raw_data['task_id'] ?? '';
                $predecessor = $rel->raw_data['pred_task_id'] ?? '';
                if ($successor !== '') {
                    $hasPredecessor[(string) $successor] = true;
                }
                if ($predecessor !== '') {
                    $hasSuccessor[(string) $predecessor] = true;
                }
            }

            foreach ($activities as $activity) {
                // DCMA point 1 covers incomplete activities; milestones are
                // legitimately open-ended on one side.
                if ($activity->status_code === 'Completed') {
                    continue;
                }
                if (in_array($activity->task_type, ['TT_StartMile', 'TT_FinMile'], true)) {
                    continue;
                }
                if (! isset($hasPredecessor[$activity->id]) && ! isset($hasSuccessor[$activity->id])) {
                    $missingLogic++;
                }
            }
        }

        $baseCount = $incompleteActivities > 0 ? $incompleteActivities : ($totalActivities > 0 ? $totalActivities : 1);
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
            $point(1, 'missing_logic', 'Logic', '≤ 5% without predecessor or successor', $assessable && $hasRelationships,
                $hasRelationships ? $missingLogic : null,
                $hasRelationships ? $pct($missingLogic, $baseCount) : null,
                $assessable && $hasRelationships ? $pct($missingLogic, $baseCount) <= 5 : null,
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
            $point(5, 'hard_constraints', 'Hard constraints', '≤ 5% of activities', $assessable,
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
                $hasDataDate ? $pct($invalidDates, $baseCount) : null,
                $assessable && $hasDataDate ? $invalidDates === 0 : null,
                $assessable ? ($hasDataDate ? null : $needsDataDate) : $noActivities),
            $point(10, 'resources', 'Resources', 'All effort activities resourced', false,
                null, null, null, 'Requires a cost/resource loading assessment.'),
            $point(11, 'missed_activities', 'Missed activities', '≤ 5% past planned finish', $assessable && $hasDataDate,
                $hasDataDate ? $missedActivities : null,
                $hasDataDate ? $pct($missedActivities, $baseCount) : null,
                $assessable && $hasDataDate ? $pct($missedActivities, $baseCount) <= 5 : null,
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
}
