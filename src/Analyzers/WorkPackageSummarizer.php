<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use TeckFuture\P6Analyzer\DTOs\ParsedXerData;

/**
 * Summarizes activities into work packages based on WBS.
 *
 * One package per WBS node, identified by its wbs_id: two nodes that share a
 * name stay separate packages, the name being only a label. Activities with no
 * wbs_id are grouped under the wbs_id and name 'unassigned'.
 */
class WorkPackageSummarizer
{
    /**
     * @return array<int, array{wbs_id: string, name: string, activity_count: int, total_duration_hr: float, total_cost: float, completed_activities: int, percent_complete_by_count: float}>
     */
    public function summarize(ParsedXerData $data): array
    {
        $activities = $data->getActivities();
        $wbsTable = $data->getTable('PROJWBS')->keyBy('wbs_id');

        $packages = [];

        foreach ($activities as $activity) {
            $wbsId = $activity->raw_data['wbs_id'] ?? 'unassigned';
            $wbsName = isset($wbsTable[$wbsId]) ? ($wbsTable[$wbsId]['wbs_name'] ?? $wbsId) : $wbsId;

            if (! isset($packages[$wbsId])) {
                $packages[$wbsId] = [
                    'wbs_id' => $wbsId,
                    'name' => $wbsName,
                    'activity_count' => 0,
                    'total_duration_hr' => 0,
                    'total_cost' => 0.0,
                    'completed_activities' => 0,
                ];
            }

            $packages[$wbsId]['activity_count']++;
            $packages[$wbsId]['total_duration_hr'] += (float) ($activity->target_drtn_hr_cnt ?? 0);
            $packages[$wbsId]['total_cost'] += (float) ($activity->target_cost ?? 0);

            if ($activity->status_code === 'Completed') {
                $packages[$wbsId]['completed_activities']++;
            }
        }

        foreach ($packages as &$package) {
            $package['percent_complete_by_count'] = $package['activity_count'] > 0
                ? round(($package['completed_activities'] / $package['activity_count']) * 100, 2)
                : 0;
        }
        unset($package);

        return array_values($packages);
    }
}
