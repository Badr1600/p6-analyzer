<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\RelationshipData;

/**
 * Analyzes relationship types (FS/SS/FF/SF) and lag distribution.
 */
class RelationshipAnalyzer
{
    /**
     * @param  Collection<int, RelationshipData>|array<int, RelationshipData>  $relationships
     * @return array{total_relationships: int, type_distribution: array, type_percentages: array, lag_distribution: array}
     */
    public function calculate(Collection|array $relationships): array
    {
        $relationships = is_array($relationships) ? collect($relationships) : $relationships;
        $distribution = [
            'FS' => 0,
            'SS' => 0,
            'FF' => 0,
            'SF' => 0,
            'unknown' => 0,
        ];

        $lags = [
            'negative' => 0,
            'zero' => 0,
            'positive' => 0,
        ];

        foreach ($relationships as $rel) {
            $type = $rel->pred_type;

            if (str_contains($type, 'FS')) {
                $distribution['FS']++;
            } elseif (str_contains($type, 'SS')) {
                $distribution['SS']++;
            } elseif (str_contains($type, 'FF')) {
                $distribution['FF']++;
            } elseif (str_contains($type, 'SF')) {
                $distribution['SF']++;
            } else {
                $distribution['unknown']++;
            }

            $lagHr = $rel->lag_hr_cnt ?? 0;

            if ($lagHr < 0) {
                $lags['negative']++;
            } elseif ($lagHr > 0) {
                $lags['positive']++;
            } else {
                $lags['zero']++;
            }
        }

        $total = $relationships->count();

        return [
            'total_relationships' => $total,
            'type_distribution' => $distribution,
            'type_percentages' => [
                'FS' => $total > 0 ? round(($distribution['FS'] / $total) * 100, 2) : 0,
                'SS' => $total > 0 ? round(($distribution['SS'] / $total) * 100, 2) : 0,
                'FF' => $total > 0 ? round(($distribution['FF'] / $total) * 100, 2) : 0,
                'SF' => $total > 0 ? round(($distribution['SF'] / $total) * 100, 2) : 0,
            ],
            'lag_distribution' => $lags,
        ];
    }
}
