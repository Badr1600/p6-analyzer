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
- Resources allocated more than this many hours per day are flagged as over-allocated.
