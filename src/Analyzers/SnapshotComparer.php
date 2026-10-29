<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;

/**
 * Diffs two ParsedXerData snapshots to highlight changes.
 *
 * Activities are matched by task_code, the stable business identifier that
 * survives re-imports (task_id is a database key that does not). An activity
 * without a task_code has nothing to be matched on, so it is skipped, as
 * ScheduleVarianceCalculator does, rather than being keyed under '' with every
 * other activity that lacks one.
 */
class SnapshotComparer
{
    /**
     * @return array{added_activities: array, deleted_activities: array, modified_activities: array, total_changes: int}
     */
    public function compare(ParsedXerData $currentData, ParsedXerData $baselineData): array
    {
        $currentActivities = $this->keyedByTaskCode($currentData);
        $baselineActivities = $this->keyedByTaskCode($baselineData);

        $added = [];
        $deleted = [];
        $modified = [];

        foreach ($currentActivities as $taskCode => $current) {
            $baseline = $baselineActivities->get($taskCode);
            if (! $baseline) {
                $added[] = $taskCode;

                continue;
            }

            $changes = [];

            if ($current->status_code !== $baseline->status_code) {
                $changes['status'] = [
                    'from' => $baseline->status_code,
                    'to' => $current->status_code,
                ];
            }

            if ($current->target_drtn_hr_cnt != $baseline->target_drtn_hr_cnt) {
                $changes['duration'] = [
                    'from' => $baseline->target_drtn_hr_cnt,
                    'to' => $current->target_drtn_hr_cnt,
                ];
            }

            $cStart = $current->target_start_date?->toDateString();
            $bStart = $baseline->target_start_date?->toDateString();
            if ($cStart !== $bStart) {
                $changes['start_date'] = [
                    'from' => $bStart,
                    'to' => $cStart,
                ];
            }

            if (! empty($changes)) {
                $modified[$taskCode] = $changes;
            }
        }

        foreach ($baselineActivities->keys() as $taskCode) {
            if (! $currentActivities->has($taskCode)) {
                $deleted[] = $taskCode;
            }
        }

        return [
            'added_activities' => $added,
            'deleted_activities' => $deleted,
            'modified_activities' => $modified,
            'total_changes' => count($added) + count($deleted) + count($modified),
        ];
    }

    /**
     * @return Collection<string, ActivityData>
     */
    private function keyedByTaskCode(ParsedXerData $data): Collection
    {
        return $data->getActivities()
            ->filter(fn (ActivityData $a) => $a->task_code !== null && $a->task_code !== '')
            ->keyBy('task_code');
    }
}
