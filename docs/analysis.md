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

Total float is bucketed in days, converting hours with the `hours_per_day` setting (default 8): `negative` is below 0,
`zero_to_ten` is 0 to 10, `eleven_to_thirty` is above 10 up to 30 and `thirty_plus` is above 30. `missing` counts the
activities that have no total float.

## DCMA 14-Point Check (`DCMA14PointChecker`)

```php
$result = $analyzer->dcma14Point('2024-01-15');
// ['assessable', 'total_activities', 'incomplete_activities',
//  'negative_float_count', 'negative_float_pct',
//  'high_float_count', 'high_float_pct',
//  'high_duration_count', 'high_duration_pct',
//  'invalid_dates_count', 'points', 'overall_score']
```

`overall_score` runs from 0 to 100. It is derived from the high float, negative float, high duration and invalid-date
checks only (weighted by `dcma_penalty_weights`), so stored scores stay comparable across versions. `assessable` is
`false` when there are no activities. The `*_pct` values are percentages of `incomplete_activities` (of
`total_activities` when none is incomplete).

`points` holds one entry for each of the 14 DCMA points, in DCMA order, and every entry has the same keys:

```php
foreach ($result['points'] as $point) {
    // ['id', 'key', 'name', 'threshold_label', 'assessable', 'count', 'percentage', 'passed', 'reason']
}
```

`count` and `percentage` are what the point measures and `passed` is the verdict against `threshold_label` (the day
counts of points 6 and 8 follow `hours_per_day`). A point that cannot be assessed has `assessable => false`,
`passed => null` and a `reason` that says what is missing.

| `id` | `key` | Assessable when |
|---|---|---|
| 1 | `missing_logic` | relationship data is provided |
| 2 | `leads` | relationship data is provided |
| 3 | `lags` | relationship data is provided |
| 4 | `relationship_types` | relationship data is provided and not empty |
| 5 | `hard_constraints` | there are activities |
| 6 | `high_float` | there are activities |
| 7 | `negative_float` | there are activities |
| 8 | `high_duration` | there are activities |
| 9 | `invalid_dates` | there are activities and a data date |
| 10 | `resources` | never: needs a cost/resource loading assessment |
| 11 | `missed_activities` | there are activities and a data date |
| 12 | `critical_path_test` | never: needs an intentional-delay simulation |
| 13 | `cpli` | never: needs an approved baseline |
| 14 | `bei` | never: needs an approved baseline |

Points 1 to 4 need the relationships as the checker's third argument. `P6AnalyzerManager::dcma14Point()` passes only
the activities and the data date, so through the manager those four points report `assessable => false`. To assess
them, call the checker directly:

```php
use TeckFuture\P6Analyzer\Analyzers\DCMA14PointChecker;

$result = (new DCMA14PointChecker)->calculate($data->getActivities(), '2024-01-15', $data->getRelationships());
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

Compares the loaded schedule with a baseline. Activities are matched by `task_code`.

```php
use TeckFuture\P6Analyzer\IO\XerParser;

$baseline = (new XerParser)->parseFile('/path/to/baseline.xer');
$result = $analyzer->scheduleVariance($baseline);
// ['total_activities_compared', 'current_activities_count', 'matched_count', 'unmatched_count',
//  'slipping_activities_count', 'average_variance_days', 'max_variance_days', 'slipping_activities']
```

`total_activities_compared` and `matched_count` count the activities found in both schedules, and `unmatched_count` is
the number of current activities with no match in the baseline (`current_activities_count` is the sum of the two). An
activity slips when its finish is later than its baseline finish by more than `min_slip_days` days (default 0).
`average_variance_days` and `max_variance_days` cover the slipping activities only.

`master_*` keys hold the baseline dates and `workbench_*` keys the current ones. The variances are in days, positive
when the current date is later:

```php
foreach ($result['slipping_activities'] as $activity) {
    // ['id', 'task_code', 'name', 'status', 'total_float_hr',
    //  'master_start', 'workbench_start', 'start_variance_days',
    //  'master_finish', 'workbench_finish', 'variance_days']
}
```

## Schedule Health (`ScheduleHealthChecker`)

```php
$result = $analyzer->scheduleHealth('2024-01-15');
// ['total_activities', 'missing_resources', 'missing_logic', 'out_of_sequence',
//  'lags_count', 'leads_count', 'details_limit',
//  'missing_resources_activities', 'missing_logic_activities', 'out_of_sequence_activities']
```

Each `*_activities` list names the activities behind the matching count and holds at most `details_limit` entries (200);
the counts stay exact when a list is capped. `out_of_sequence` needs the data date.

```php
foreach ($result['missing_logic_activities'] as $activity) {
    // ['id', 'task_code', 'name']
}
```

## Resource Utilization (`ResourceUtilizationAnalyzer`)

```php
$result = $analyzer->resourceUtilization('week', 8.0);
// ['utilization', 'over_allocations', 'total_resources_analyzed', 'roles']

$result = $analyzer->resourceUtilization('week', 8.0, weekStartsOn: 6); // weeks start on Saturday
```

Supported periods: `'day'`, `'week'`, `'month'`.

`over_allocations` has one entry per resource (or role bucket) per calendar day on which the hours of all its
assignments add up to more than the daily limit (`resource_overallocation_daily_limit`, default 8):

```php
['resource' => 'John', 'date' => '2024-01-01', 'qty_per_day' => 12.0, 'activity' => 'Build',
 'activities' => [['activity' => 'Build', 'qty_per_day' => 6.0], ['activity' => 'Paint', 'qty_per_day' => 6.0]]]
```

`qty_per_day` is the day's total and `activities` lists each contributing activity with its hours. `activity`
repeats the first contributor and is kept for code written before 2.5.0.

`weekStartsOn` (optional, `'week'` period only) is Carbon's day number, `0` (Sunday) to `6` (Saturday). It groups
each `'week'` key (`'Y-m-d (Week)'`) on the given day. Left `null` (the default), weeks start on the Carbon locale's
first day (Monday for `en`) — unchanged from before 2.3.0. `'day'` and `'month'` ignore it.

A line with no resource but a role (`rsrc_id` empty, `role_id` set) is counted by role, with no `RSRC` row needed. Its
bucket key in `utilization` is `role:{role_id}`, and `roles['role:{role_id}']` describes it:
`['kind' => 'role', 'role_id' => '12', 'label' => 'Planner']`. The label is the optional trailing `$roleLabels`
argument (`role_id => label`), else `ROLES.role_short_name`, else `Role {id}`. Over-allocations of a role carry the
bucket key in `resource` plus `kind` and `label`. Resource buckets and their output are unchanged; `roles` is empty
when no line is role-only.

```php
$result = $analyzer->resourceUtilization('week', 8.0, null, [12 => 'Planner']);
```

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
