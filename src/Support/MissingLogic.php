<?php

namespace TeckFuture\P6Analyzer\Support;

use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\DTOs\RelationshipData;

/**
 * Shared rule behind DCMA point 1 (logic) and the schedule health check's
 * `missing_logic`, so the two always flag the same activities.
 *
 * An activity is missing logic when it is not complete and has no predecessor
 * OR no successor. Two kinds are treated differently: WBS summary activities
 * are never examined (they carry no logic of their own), and a milestone is
 * open-ended on one side by nature, so a start milestone needs only a
 * successor and a finish milestone only a predecessor.
 */
final class MissingLogic
{
    /**
     * The activities the rule examines: those that are not complete, except
     * WBS summaries. Milestones are in, because they are checked on one side.
     * It is the population DCMA point 1 measures its percentage against.
     *
     * @param  Collection<int, ActivityData>  $activities
     * @return Collection<int, ActivityData>
     */
    public static function inScope(Collection $activities): Collection
    {
        return $activities
            ->filter(fn (ActivityData $activity) => $activity->status_code !== 'Completed'
                && $activity->task_type !== ActivityData::WBS_SUMMARY_TASK_TYPE)
            ->values();
    }

    /**
     * @param  Collection<int, ActivityData>  $activities
     * @param  Collection<int, RelationshipData>  $relationships
     * @return Collection<int, ActivityData> The activities missing logic, in the order they were given.
     */
    public static function find(Collection $activities, Collection $relationships): Collection
    {
        $hasPredecessor = [];
        $hasSuccessor = [];

        foreach ($relationships as $relationship) {
            $successor = (string) ($relationship->raw_data['task_id'] ?? '');
            $predecessor = (string) ($relationship->raw_data['pred_task_id'] ?? '');

            if ($successor !== '') {
                $hasPredecessor[$successor] = true;
            }
            if ($predecessor !== '') {
                $hasSuccessor[$predecessor] = true;
            }
        }

        return self::inScope($activities)
            ->filter(function (ActivityData $activity) use ($hasPredecessor, $hasSuccessor) {
                // Nothing comes before a start milestone, or after a finish milestone.
                $needsPredecessor = $activity->task_type !== ActivityData::START_MILESTONE_TASK_TYPE;
                $needsSuccessor = $activity->task_type !== ActivityData::FINISH_MILESTONE_TASK_TYPE;

                return ($needsPredecessor && ! isset($hasPredecessor[$activity->id]))
                    || ($needsSuccessor && ! isset($hasSuccessor[$activity->id]));
            })
            ->values();
    }
}
