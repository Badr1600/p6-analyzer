<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Carbon\Carbon;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\Support\MissingLogic;

/**
 * Performs general schedule health checks beyond DCMA 14-point.
 *
 * `missing_logic` follows the rule of DCMA point 1 (see MissingLogic): an
 * incomplete activity without a predecessor or without a successor, except
 * that a start milestone needs only a successor and a finish milestone only
 * a predecessor.
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

        foreach ($relationships as $rel) {
            $lag = $rel->lag_hr_cnt ?? 0;
            if ($lag > 0) {
                $health['lags_count']++;
            } elseif ($lag < 0) {
                $health['leads_count']++;
            }
        }

        $assignments = $data->getTable('TASKRSRC')->groupBy('task_id');
        $dataDateObj = $dataDate ? Carbon::parse($dataDate) : null;

        $describe = fn (ActivityData $activity) => [
            'id' => $activity->id,
            'task_code' => $activity->task_code,
            'name' => $activity->name,
        ];

        $missingLogic = MissingLogic::find($activities, $relationships);
        $health['missing_logic'] = $missingLogic->count();
        $health['missing_logic_activities'] = $missingLogic->take(self::DETAILS_LIMIT)->map($describe)->all();

        foreach ($activities as $activity) {
            if (! isset($assignments[$activity->id])) {
                $health['missing_resources']++;
                if (count($health['missing_resources_activities']) < self::DETAILS_LIMIT) {
                    $health['missing_resources_activities'][] = $describe($activity);
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
