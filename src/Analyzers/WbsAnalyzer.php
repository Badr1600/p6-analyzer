<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use TeckFuture\P6Analyzer\DTOs\ParsedXerData;

/**
 * Analyzes Work Breakdown Structure (WBS) metrics.
 */
class WbsAnalyzer
{
    /**
     * @return array{total_wbs_elements: int, wbs_metrics: array}
     */
    public function calculate(ParsedXerData $data): array
    {
        $wbsTable = $data->getTable('PROJWBS');
        $activities = $data->getActivities();

        $wbsActivityCounts = [];
        $wbsCost = [];

        foreach ($activities as $activity) {
            $wbsId = $activity->raw_data['wbs_id'] ?? 'unassigned';

            if (! isset($wbsActivityCounts[$wbsId])) {
                $wbsActivityCounts[$wbsId] = 0;
                $wbsCost[$wbsId] = 0.0;
            }

            $wbsActivityCounts[$wbsId]++;
            $wbsCost[$wbsId] += (float) ($activity->target_cost ?? 0);
        }

        $wbsDetails = [];
        foreach ($wbsTable as $wbs) {
            $wbsId = $wbs['wbs_id'];
            $wbsDetails[$wbsId] = [
                'name' => $wbs['wbs_name'] ?? 'Unknown',
                'short_name' => $wbs['wbs_short_name'] ?? '',
                'activity_count' => $wbsActivityCounts[$wbsId] ?? 0,
                'total_cost' => $wbsCost[$wbsId] ?? 0.0,
            ];
        }

        return [
            'total_wbs_elements' => $wbsTable->count(),
            'wbs_metrics' => $wbsDetails,
        ];
    }
}
