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

EVM runs under an explicit **measurement method** — one basis for the whole
calculation, never mixed per activity:

| Method | BAC / AC / Remaining source | Unit |
|---|---|---|
| `cost` | `target_cost` / `act_cost` / `remain_cost` (TASKRSRC rollup) | `currency` |
| `labor_units` | `target_work_qty` / `act_work_qty` / `remain_work_qty` (native TASK) | `labor_hours` |
| `duration` | `target_drtn_hr_cnt` / — / `remain_drtn_hr_cnt` | `schedule_hours` |
| `auto` *(default)* | Best-covered method above, preferring cost, then labor units | resolved |

```php
use TeckFuture\P6Analyzer\Enums\EvmMethod;

$result = $analyzer->earnedValue('2024-06-15');                        // auto
$result = $analyzer->earnedValue('2024-06-15', EvmMethod::LaborUnits); // explicit
// ['method', 'unit', 'data_date',
//  'planned_value', 'earned_value', 'actual_cost', 'budget_at_completion',
//  'cost_variance', 'schedule_variance', 'cpi', 'spi',
//  'estimate_at_completion', 'estimate_to_complete', 'variance_at_completion', 'tcpi',
//  'coverage' => ['total_activities', 'measurable_activities', 'activities_with_data', 'coverage_pct'],
//  'actual_cost_sources' => ['tracked', 'inferred_from_remaining', 'status', 'none']]
```

`cpi`, `spi`, and `tcpi` are `null` — not a fake `1.0` — when their
denominators are zero (no actuals tracked, no planned value at the data date).

All methods in one pass, mirroring P6's paired By-Cost / By-Labor-Units
summaries:

```php
$all = $analyzer->earnedValueReport('2024-06-15');
// ['data_date', 'by_cost', 'by_labor_units', 'by_duration', 'recommended']
```

**Calculation semantics** (each toggleable via `config('p6-analyzer.evm.*')`
or the calculator's constructor options):

- **Actual-cost resolution**: tracked actuals win; otherwise, when the
  activity carries a *present* remaining amount, `AC = BAC − remaining`
  (`infer_actuals_from_remaining`). An absent remaining field never triggers
  inference — Completed status is the final fallback. The result's
  `actual_cost_sources` reports which path each activity took.
- **Percent complete**: honors the activity's P6 `complete_pct_type` —
  `CP_Phys` uses physical %, `CP_Drtn` uses `(target − remaining) / target`
  duration, `CP_Units` uses `actual / (actual + remaining)` units
  (`respect_complete_pct_type`). Completed earns 100%, Not Started earns 0%
  (`status_overrides_percent`).
- **Planned value**: an explicitly supplied `planned_value` wins (cost method
  only); otherwise PV is time-phased linearly between the target dates
  relative to the data date. Without a data date, PV is 0 and `spi` is `null`.
- **Exclusions**: WBS summary bars (`TT_WBS`) and milestones (`TT_Mile`,
  `TT_FinMile`) never contribute to sums or coverage.

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
