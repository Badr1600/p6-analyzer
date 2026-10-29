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
 * OR no successor. Three kinds are never flagged: completed activities, WBS
 * summary activities (they carry no logic of their own) and milestones, which
 * are legitimately open-ended on one side.
 */
final class MissingLogic
{
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

        return $activities
            ->filter(fn (ActivityData $activity) => self::isChecked($activity)
                && (! isset($hasPredecessor[$activity->id]) || ! isset($hasSuccessor[$activity->id])))
            ->values();
    }

    /**
     * Whether the logic rule applies to the activity at all.
     */
    private static function isChecked(ActivityData $activity): bool
    {
        return $activity->status_code !== 'Completed'
            && $activity->task_type !== ActivityData::WBS_SUMMARY_TASK_TYPE
            && ! in_array($activity->task_type, ActivityData::MILESTONE_TASK_TYPES, true);
    }
}
