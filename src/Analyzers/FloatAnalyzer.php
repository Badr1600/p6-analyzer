<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\Support\Config;

/**
 * Analyzes total float distribution across activities.
 */
class FloatAnalyzer
{
    /**
     * Calculate float distribution.
     *
     * @param  Collection<int, ActivityData>|array<int, ActivityData>  $activities
     * @return array{total_activities: int, distribution: array{negative: int, zero_to_ten: int, eleven_to_thirty: int, thirty_plus: int, missing: int}}
     */
    public function calculate(Collection|array $activities): array
    {
        $activities = is_array($activities) ? collect($activities) : $activities;
        // A zero, negative or non-numeric setting cannot turn hours into days: use the 8 hour default.
        $hoursPerDay = (float) Config::get('hours_per_day', 8);
        $hoursPerDay = $hoursPerDay > 0 ? $hoursPerDay : 8.0;
        $distribution = [
            'negative' => 0,
            'zero_to_ten' => 0,
            'eleven_to_thirty' => 0,
            'thirty_plus' => 0,
            'missing' => 0,
        ];

        foreach ($activities as $activity) {
            $floatHr = $activity->total_float_hr_cnt;

            if ($floatHr === null) {
                $distribution['missing']++;

                continue;
            }

            $floatDays = $floatHr / $hoursPerDay;

            if ($floatDays < 0) {
                $distribution['negative']++;
            } elseif ($floatDays <= 10) {
                $distribution['zero_to_ten']++;
            } elseif ($floatDays <= 30) {
                $distribution['eleven_to_thirty']++;
            } else {
                $distribution['thirty_plus']++;
            }
        }

        return [
            'total_activities' => $activities->count(),
            'distribution' => $distribution,
        ];
    }
}
