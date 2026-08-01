<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Carbon\Carbon;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;

/**
 * Performs general schedule health checks beyond DCMA 14-point.
 *
 * Alongside each count, the offending activities travel as drill-down lists
 * (id, task_code, name), capped at `details_limit` rows per check — the
 * counts remain authoritative when a list is capped.
 */
class ScheduleHealthChecker
{
    /** Upper bound per drill-down list; keeps cached payloads bounded. */
    private const DETAILS_LIMIT = 200;

    /**
     * @return array<string, mixed>
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
            'details_limit' => self::DETAILS_LIMIT,
            'missing_resources_activities' => [],
            'missing_logic_activities' => [],
            'out_of_sequence_activities' => [],
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

        $describe = fn (ActivityData $activity) => [
            'id' => $activity->id,
            'task_code' => $activity->task_code,
            'name' => $activity->name,
        ];

        foreach ($activities as $activity) {
            if (! isset($assignments[$activity->id])) {
                $health['missing_resources']++;
                if (count($health['missing_resources_activities']) < self::DETAILS_LIMIT) {
                    $health['missing_resources_activities'][] = $describe($activity);
                }
            }

            $type = $activity->raw_data['task_type'] ?? '';
            if (! isset($hasPredecessor[$activity->id]) && ! isset($hasSuccessor[$activity->id])) {
                if ($type !== 'TT_StartMile' && $type !== 'TT_FinMile') {
                    $health['missing_logic']++;
                    if (count($health['missing_logic_activities']) < self::DETAILS_LIMIT) {
                        $health['missing_logic_activities'][] = $describe($activity);
                    }
                }
            }

            if ($dataDateObj && $activity->act_start_date && $activity->act_start_date->isAfter($dataDateObj)) {
                $health['out_of_sequence']++;
                if (count($health['out_of_sequence_activities']) < self::DETAILS_LIMIT) {
                    $health['out_of_sequence_activities'][] = $describe($activity);
                }
            }
        }

        return $health;
    }
}
