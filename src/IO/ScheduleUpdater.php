<?php

namespace TeckFuture\P6Analyzer\IO;

use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\Exceptions\XerParseException;

/**
 * Mutates a ParsedXerData structure in memory.
 */
class ScheduleUpdater
{
    /**
     * Update an activity in the parsed XER data.
     *
     * @param  string  $taskId  The task_id of the activity to update.
     * @param  array<string, string>  $updates  Key-value pairs of fields to update.
     * @return bool True if the activity was found and updated.
     */
    public function updateActivity(ParsedXerData $data, string $taskId, array $updates): bool
    {
        $activities = $data->getTable('TASK');

        foreach ($activities as $index => $activityRow) {
            if (($activityRow['task_id'] ?? '') === $taskId) {
                foreach ($updates as $key => $value) {
                    $activityRow[$key] = $value;
                }
                $activities->put($index, $activityRow);

                return true;
            }
        }

        return false;
    }

    /**
     * Perform a batch update on multiple activities.
     *
     * @param  array<int, array{task_id: string, updates: array<string, string>}>  $batchUpdates
     * @return int Number of activities successfully updated.
     */
    public function batchUpdate(ParsedXerData $data, array $batchUpdates): int
    {
        $updatedCount = 0;

        foreach ($batchUpdates as $batch) {
            if (isset($batch['task_id'], $batch['updates'])) {
                if ($this->updateActivity($data, $batch['task_id'], $batch['updates'])) {
                    $updatedCount++;
                }
            }
        }

        return $updatedCount;
    }

    /**
     * Add a new activity to the TASK table.
     *
     * @param  array<string, string>  $activityData  Must contain a `task_id` key.
     *
     * @throws XerParseException If task_id is missing.
     */
    public function addActivity(ParsedXerData $data, array $activityData): void
    {
        if (empty($activityData['task_id'])) {
            throw new XerParseException('New activity must have a task_id');
        }

        $activities = $data->getTable('TASK');

        $firstRow = $activities->first() ?? [];
        $headers = array_keys($firstRow);

        $newRow = [];
        foreach ($headers as $header) {
            $newRow[$header] = $activityData[$header] ?? '';
        }

        $activities->push($newRow);
    }
}
