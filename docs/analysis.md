# Analysis & Calculators

All analyzers can be used standalone or through the `P6AnalyzerManager`.

## Critical Path (`CriticalPathCalculator`)

Identifies activities with total float at or below a threshold.

```php
$result = $analyzer->criticalPath();
// ['total_activities', 'critical_activities_count', 'critical_path_percentage', 'critical_activities']
```

## Float Distribution (`FloatAnalyzer`)

```php
$result = $analyzer->floatAnalysis();
// ['total_activities', 'distribution' => ['negative', 'zero_to_ten', 'eleven_to_thirty', 'thirty_plus', 'missing']]
```

## DCMA 14-Point Check (`DCMA14PointChecker`)

```php
$result = $analyzer->dcma14Point('2024-01-15');
// ['total_activities', 'incomplete_activities', 'negative_float_count', 'high_float_count', 'overall_score']
```

## Earned Value (`EarnedValueCalculator`)

```php
$result = $analyzer->earnedValue();
// ['planned_value', 'earned_value', 'actual_cost', 'budget_at_completion', 'cost_variance', 'schedule_variance', 'cpi', 'spi', 'estimate_at_completion']
```

## Relationship Analysis (`RelationshipAnalyzer`)

```php
$result = $analyzer->relationshipAnalysis();
// ['total_relationships', 'type_distribution', 'type_percentages', 'lag_distribution']
```

## Schedule Variance (`ScheduleVarianceCalculator`)

```php
$baseline = (new XerParser)->parseFile('/path/to/baseline.xer');
$result = $analyzer->scheduleVariance($baseline->getData());
// ['total_activities_compared', 'slipping_activities_count', 'average_variance_days', 'slipping_activities']
```

## Schedule Health (`ScheduleHealthChecker`)

```php
$result = $analyzer->scheduleHealth('2024-01-15');
// ['total_activities', 'missing_resources', 'missing_logic', 'out_of_sequence', 'lags_count', 'leads_count']
```

## Resource Utilization (`ResourceUtilizationAnalyzer`)

```php
$result = $analyzer->resourceUtilization('week', 8.0);
// ['utilization', 'over_allocations', 'total_resources_analyzed']
```

Supported periods: `'day'`, `'week'`, `'month'`.

## WBS Analysis (`WbsAnalyzer`)

```php
$result = $analyzer->wbsAnalysis();
// ['total_wbs_elements', 'wbs_metrics']
```

## Lookahead (`LookaheadGenerator`)

```php
$result = $analyzer->lookahead('2024-01-15', 3);
// ['data_date', 'lookahead_weeks', 'end_date', 'activity_count', 'activities']
```

## Progress Summary (`ProgressSummarizer`)

```php
$result = $analyzer->progress();
// ['total_activities', 'status_counts', 'status_percentages', 'overall_percent_complete_by_duration']
```

## Work Packages (`WorkPackageSummarizer`)

```php
$result = $analyzer->workPackages();
// Array of ['name', 'activity_count', 'total_duration_hr', 'total_cost', 'completed_activities', 'percent_complete_by_count']
```
