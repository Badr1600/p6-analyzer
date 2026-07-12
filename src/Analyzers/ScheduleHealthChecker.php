<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Carbon\Carbon;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;

/**
 * Performs general schedule health checks beyond DCMA 14-point.
 */
class ScheduleHealthChecker
{
    /**
     * @return array{total_activities: int, missing_resources: int, missing_logic: int, out_of_sequence: int, lags_count: int, leads_count: int}
     */
    public function check(ParsedXerData $data, ?string $dataDate = null): array
    {
        $activities = $data->getActivities();
        $relationships = $data->getRelationships();

        $health = [
            'total_activities' => $activities->count(),
            'missing_resources' => 0,
            'missing_logic' => 0,
            'out_of_sequence' => 0,
            'lags_count' => 0,
            'leads_count' => 0,
        ];

        $hasPredecessor = [];
        $hasSuccessor = [];

        foreach ($relationships as $rel) {
            $lag = $rel->lag_hr_cnt ?? 0;
            if ($lag > 0) {
                $health['lags_count']++;
            } elseif ($lag < 0) {
                $health['leads_count']++;
            }

            $successor = $rel->raw_data['task_id'] ?? '';
            $predecessor = $rel->raw_data['pred_task_id'] ?? '';

            if ($successor) {
                $hasPredecessor[$successor] = true;
            }
            if ($predecessor) {
                $hasSuccessor[$predecessor] = true;
            }
        }

        $assignments = $data->getTable('TASKRSRC')->groupBy('task_id');
        $dataDateObj = $dataDate ? Carbon::parse($dataDate) : null;

        foreach ($activities as $activity) {
            if (! isset($assignments[$activity->id])) {
                $health['missing_resources']++;
            }

            $type = $activity->raw_data['task_type'] ?? '';
            if (! isset($hasPredecessor[$activity->id]) && ! isset($hasSuccessor[$activity->id])) {
                if ($type !== 'TT_StartMile' && $type !== 'TT_FinMile') {
                    $health['missing_logic']++;
                }
            }

            if ($dataDateObj && $activity->act_start_date && $activity->act_start_date->isAfter($dataDateObj)) {
                $health['out_of_sequence']++;
            }
        }

        return $health;
    }
}
