# Configuration

All configuration values have sensible defaults and are optional to publish.

## Config Keys

### `critical_float_threshold`
- **Type:** `int` (hours)
- **Default:** `0`
- Activities with total float at or below this threshold are considered critical.

### `high_float_threshold`
- **Type:** `int` (hours)
- **Default:** `352` (44 business days × 8h)
- Activities exceeding this float value are flagged in the DCMA check.

### `high_duration_threshold`
- **Type:** `int` (hours)
- **Default:** `352` (44 business days × 8h)
- Activities exceeding this duration are flagged in the DCMA check.

### `hours_per_day`
- **Type:** `float` (hours)
- **Default:** `8`
- Working hours per day, used to convert hour-based float and duration values to days: the buckets of the float distribution (`floatAnalysis()`) and the day counts in the DCMA point threshold labels. `high_float_threshold` and `high_duration_threshold` stay in hours. A value of zero or below, or one that is not a number, is ignored and `8` is used.

### `dcma_penalty_weights`
- **Type:** `array`
- **Default:**
  ```php
  [
      'high_float' => 1,
      'negative_float' => 2,
      'high_duration' => 1,
      'invalid_dates' => 10,
  ]
  ```
- Penalty weights used in the DCMA 14-point overall score calculation. Higher weights mean larger penalties.

### `resource_overallocation_daily_limit`
- **Type:** `float` (hours)
- **Default:** `8`
- A resource whose assignments add up to more than this many hours on one day is flagged as over-allocated for that day.

### `min_slip_days`
- **Type:** `float` (days)
- **Default:** `0`
- Schedule variance counts an activity as slipping only when its finish is later than the baseline finish by more than this many days. With the default, any later finish counts.

### `evm.default_method`
- **Type:** `string` — `'cost'` | `'labor_units'` | `'duration'` | `'auto'`
- **Default:** `'auto'`
- Measurement basis used by `earnedValue()` when no method is passed. `auto` picks the best-covered method per project (preferring cost, then labor units) — units are never mixed across activities.

### `evm.auto_coverage_threshold`
- **Type:** `float` (0–1)
- **Default:** `0.5`
- Minimum share of measurable activities that must carry data for `auto` to select cost or labor units before falling back to duration. It is a share from 0 to 1 (`0.5` is half of them), not a percentage like `coverage_pct` in the earned value result, which runs from 0 to 100.

### `evm.infer_actuals_from_remaining`
- **Type:** `bool`
- **Default:** `true`
- When actuals are not tracked, derive `AC = BAC − remaining` for activities that carry a present remaining amount. Absent remaining fields never trigger inference.

### `evm.respect_complete_pct_type`
- **Type:** `bool`
- **Default:** `true`
- Honor each activity's P6 `complete_pct_type` (`CP_Phys` / `CP_Drtn` / `CP_Units`) when selecting the percent complete that earns value. This applies to the earned value calculation only: `progress()` always honors the type.

### `evm.status_overrides_percent`
- **Type:** `bool`
- **Default:** `true`
- Completed activities earn 100% and Not Started activities earn 0%, regardless of stale percent fields. This applies to the earned value calculation only: `progress()` always applies the override.
