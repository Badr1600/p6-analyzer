# Upgrading from 1.x to 2.x

Version 2.0 rebuilt earned value management (EVM) around explicit measurement methods. Code that
calls `earnedValue()` or `EarnedValueCalculator` needs the checks below; nothing else changed.

## 1. Renamed result keys

| 1.x key | 2.x replacement |
|---|---|
| `source` | `method` (`cost`, `labor_units` or `duration`) and `unit` |
| `has_cost_data`, `cost_data_coverage` | `coverage` (measurable activities vs activities that carry data) |
| `duration_fallback_count` | `actual_cost_sources` (tracked / inferred / status / none counts) |

## 2. `cpi`, `spi` and `tcpi` can be `null`

When a denominator is zero these indices are now `null` instead of a fake `1.0`. Handle `null`
before formatting or comparing them.

## 3. One measurement method per calculation

1.x fell back from cost to duration activity by activity. 2.x applies one method to the whole
calculation, and activities without data for that method are counted in `coverage` instead.
If you relied on a specific basis, pass it explicitly:

```php
use TeckFuture\P6Analyzer\Enums\EvmMethod;

$evm = $analyzer->earnedValue('2024-01-15', EvmMethod::Cost);
```

Without a method, `auto` (config `p6-analyzer.evm.default_method`) picks the best-covered method
for each project.

## 4. Calendar-hours actual cost removed

Duration-based actual cost no longer counts calendar hours (nights and weekends) against work-hour
budgets. The duration method now infers actual cost from `remain_drtn_hr_cnt` and activity status.

## 5. New `evm` configuration block

If you published `config/p6-analyzer.php`, copy the `evm` block from the package's config file
(`default_method`, `auto_coverage_threshold`, `infer_actuals_from_remaining`,
`respect_complete_pct_type`, `status_overrides_percent`). Re-publishing with `--force` also works,
but it overwrites your own changes:

```bash
php artisan vendor:publish --tag=p6-analyzer-config --force
```

See the [2.0.0 entry in the CHANGELOG](../CHANGELOG.md) for the full list of changes.
