# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

### Added
- A GitHub Actions workflow that runs the tests on PHP 8.3 and 8.4 against Laravel 12 and 13, with
  the lowest and the latest dependencies, and checks `composer.json` and code style.
- `SECURITY.md` (private reporting through GitHub), `CONTRIBUTING.md` and `.editorconfig`.
- `phpunit.xml.dist`. Without a PHPUnit configuration, Pest failed to start on a fresh install
  ("Could not read XML from file "--cache-directory"").
- Support links (issues, source, docs, security policy) and a homepage in `composer.json`.

### Changed
- `nesbot/carbon` is now required as `^3.8.4`. Carbon 2 was never installable together with
  Laravel 12 or 13.
- The `orchestra/testbench` dev requirement also allows `^11.0`, so the tests run on Laravel 13.
- `docs/upgrading.md` now explains upgrading from 1.x to 2.x.
- The test suite drops duplicate tests and mutation-testing notes, and checks concrete results
  where it used to check only types or `>= 0`.

## [2.4.1] - 2026-10-28

### Changed
- `composer.json` no longer hard-codes a `version` field. The version now comes from the git tag alone, so
  Packagist no longer skips a tag whose number differs from that field.
- The archive Composer downloads no longer includes `tests/`, `docs/`, `.gitattributes` or `.gitignore`.

## [2.4.0] - 2026-10-16

### Added
- `ResourceUtilizationAnalyzer::calculate()` and `P6AnalyzerManager::resourceUtilization()` count role-only
  assignments (TASKRSRC rows with an empty `rsrc_id` and a `role_id`) by role, with no RSRC table needed. They land in
  `utilization` under `role:{role_id}`, and a new `roles` map describes each bucket (`kind`, `role_id`, `label`). The
  label comes from the new optional trailing `roleLabels` argument (`role_id => label`), else `ROLES.role_short_name`,
  else `Role {id}`. Role over-allocations also carry `kind` and `label`. Resource output is unchanged.

## [2.3.0] - 2026-09-29

### Added
- `ResourceUtilizationAnalyzer::calculate()` and `P6AnalyzerManager::resourceUtilization()` accept an optional
  `weekStartsOn` day number (0 Sunday to 6 Saturday) for the `week` period. Without it weeks start as before, on
  the Carbon locale's first day.

## [2.2.0] - 2026-09-27

### Fixed
- `XerParser` truncated values that P6 writes across several physical lines.
  Only lines starting with `ERMHDR`, `%T`, `%F` or `%R` were read, so
  `CALENDAR.clndr_data` (work week, hours and exception dates) was cut at
  `(0||CalendarData()(`. A line that starts with none of `ERMHDR`, `%T`, `%F`,
  `%R` or `%E` is now a continuation of the current table's last row: it is
  appended, after a newline, to that row's last value. Lines seen before a
  table has any row are still ignored, and single-line tables parse exactly as
  before (same padding and slicing of values).

## [2.1.1] - 2026-08-01

### Fixed
- `ResourceUtilizationAnalyzer` silently under-allocated hours under Carbon 3:
  XER dates carry times and `diffInDays` now returns a fraction, inflating
  the per-day divisor while the loop iterates whole days (~5% of hours
  vanished on a typical week-long assignment). Allocation now counts whole
  days.

### Changed
- `ResourceUtilizationAnalyzer` prefers the TASKRSRC assignment's own
  `target_start_date`/`target_end_date` window over the activity dates
  (falling back to activity dates as before) — a resource assigned for one
  week of a month-long activity no longer has its hours smeared across the
  whole activity.

## [2.1.0] - 2026-08-01

Schedule comparison rebuilt on a working join, the DCMA table extended to its
honest scope, and drill-down detail for the health checks.

### Fixed
- `ScheduleVarianceCalculator` matched activities by `activity_id`, which is
  always null for imported XER data — `keyBy(null)` collapsed the entire
  baseline onto one arbitrary row and every activity was compared against it.
  Matching is now by `task_code` (as `SnapshotComparer` already does), so
  every figure the calculator has ever produced for XER-imported data should
  be considered invalid before this release.

### Changed
- `ScheduleVarianceCalculator::calculate()` `total_activities_compared` now
  counts matched pairs (the raw current-set size moved to
  `current_activities_count`); slipping rows gain `status`, `total_float_hr`,
  start dates and a signed `start_variance_days`; a minimum-slip threshold is
  available as a third parameter or the `min_slip_days` config key.
- `DCMA14PointChecker::calculate()` accepts an optional relationships
  collection as a third parameter, unlocking the logic/leads/lags/
  relationship-type points.

### Added
- `DCMA14PointChecker` now emits a `points` table representing all 14 DCMA
  assessment points — ten assessable from an imported schedule (logic, leads,
  lags, relationship types, hard constraints, high float, negative float,
  high duration, invalid dates, missed activities), each with count,
  percentage, DCMA threshold and pass/fail; the four that require an approved
  baseline or delay simulation (resources, critical path test, CPLI, BEI)
  are reported as not assessable with a reason instead of being fabricated.
  An `assessable` flag distinguishes an empty schedule from a perfect one.
  The `overall_score` formula is unchanged so stored score history remains
  comparable.
- `ScheduleHealthChecker` returns drill-down lists (`*_activities` with id,
  task_code, name; capped at `details_limit` = 200) alongside each count.

## [2.0.0] - 2026-07-31

Earned Value Management rebuilt around explicit measurement methods. EVM now
runs under a single basis for the whole calculation — units are never mixed
across activities.

### Added
- `EvmMethod` enum: `cost` | `labor_units` | `duration` | `auto`; `auto`
  resolves the best-covered method per project (preferring cost, then labor
  units, at a configurable coverage threshold)
- Labor-units measurement basis using the TASK table's native work quantities
  (`target_work_qty` / `act_work_qty` / `remain_work_qty`), mirroring P6's
  "By Labor Units" summary metrics
- `P6AnalyzerManager::earnedValue(?string $dataDate, EvmMethod|string|null $method)`
  method parameter, with a `evm.default_method` config fallback
- `P6AnalyzerManager::earnedValueReport()` / `EarnedValueCalculator::report()` —
  every method in one pass (`by_cost`, `by_labor_units`, `by_duration`,
  `recommended`), mirroring P6's paired By-Cost / By-Labor-Units summaries
- EVM result metadata: `method`, `unit`, `coverage` (measurable activities vs
  activities with data), and `actual_cost_sources` (tracked / inferred /
  status / none per-activity counts)
- EAC family completed: `estimate_to_complete`, `variance_at_completion`, `tcpi`
- Actual-cost inference: `AC = BAC − remaining` for activities that carry a
  present remaining amount, for schedules that track remaining cost but never
  record actuals (`evm.infer_actuals_from_remaining`)
- P6 `complete_pct_type` support: `CP_Drtn` earns from duration progress,
  `CP_Units` from unit progress (`evm.respect_complete_pct_type`)
- Status overrides: Completed earns 100%, Not Started earns 0%
  (`evm.status_overrides_percent`)
- `CostInference::inferActual()` support helper — the shared
  planned/tracked/remaining resolution rule for host applications
- `ActivityEvmInput` DTO — the per-activity, per-method extraction shape
- `ActivityData` typed fields: `remain_cost`, `target_work_qty`,
  `act_work_qty`, `remain_work_qty`, `remain_drtn_hr_cnt`,
  `complete_pct_type`, `task_type`
- `ParsedXerData::getActivities()` now rolls TASKRSRC costs
  (`target_cost`, `act_reg_cost` + `act_ot_cost`, `remain_cost`) up to each
  activity
- `EarnedValueCalculator` constructor accepts an options array overriding
  `config('p6-analyzer.evm.*')`
- `evm.*` config block with all calculation toggles

### Changed
- **Breaking:** per-activity cost→duration fallback removed — the measurement
  method applies to the whole calculation; activities lacking data for the
  chosen method are reported in `coverage` instead of switching units mid-sum
- **Breaking:** `cpi`, `spi` (and the new `tcpi`) are `null` instead of a
  fake `1.0` when their denominators are zero
- **Breaking:** EVM result keys `source`, `has_cost_data`,
  `cost_data_coverage`, and `duration_fallback_count` replaced by `method`,
  `unit`, `coverage`, and `actual_cost_sources`
- `ActivityData` reads `phys_complete_pct` (the actual XER column) before
  `phys_comp_pct` for physical percent complete
- `ProgressSummarizer::summarize()` also accepts `Collection<ActivityData>`
  or arrays, not just `ParsedXerData`
- WBS summary bars (`TT_WBS`) and milestones (`TT_Mile`, `TT_FinMile`) are
  excluded from EVM sums and coverage denominators
- `estimate_at_completion` divides by the unrounded CPI (no rounding-error
  propagation)
- Config reads survive environments where framework helpers are autoloaded
  without a booted application (standalone CLI usage)

### Removed
- **Breaking:** calendar-hours actual-cost estimation for duration-based
  activities (counted nights and weekends against work-hour budgets); the
  duration method now infers from `remain_drtn_hr_cnt` and status instead

### Fixed
- An absent `remain_cost` field no longer reads as "zero remaining", which
  assigned full BAC as actual cost to untouched in-progress activities

## [1.2.0] - 2026-07-28

### Changed
- `EarnedValueCalculator::calculate()` now accepts an optional `$dataDate` parameter
- `P6AnalyzerManager::earnedValue()` now accepts an optional `$dataDate` parameter

### Improved
- EVM calculations fall back to duration (hours) when `target_cost` is zero
- Planned Value is estimated from schedule dates when not explicitly provided
- Actual Cost is approximated from elapsed duration for in-progress activities using duration fallback

## [1.1.0] - 2026-07-12

### Changed
- Expanded test coverage for the analyzers and DTOs. The package code is unchanged.

## [1.0.0] - 2026-07-12

### Added
- XER file parsing (`XerParser`) with file and string input support
- 14 schedule analyzers: CriticalPathCalculator, FloatAnalyzer, DCMA14PointChecker, EarnedValueCalculator, RelationshipAnalyzer, ScheduleVarianceCalculator, ScheduleHealthChecker, ResourceUtilizationAnalyzer, WbsAnalyzer, LookaheadGenerator, WorkPackageSummarizer, ProgressSummarizer, ReportGenerator, SnapshotComparer
- `P6AnalyzerManager` fluent API for all operations
- `P6Analyzer` Facade for Laravel integration
- `P6AnalyzerServiceProvider` with auto-discovery and publishable config
- XER export (`XerExporter`) for writing modified schedules
- CSV export (`CsvExporter`) for tabular data export
- `ScheduleQueryEngine` for searching/filtering parsed data
- `ScheduleUpdater` for in-memory schedule modifications
- Custom `XerParseException` for error handling
- Status code normalization (P6 codes ↔ human-friendly strings)
- 77 tests with 218 assertions
- Architecture tests ensuring no `App\` namespace or Spatie Data leaks
- Comprehensive documentation in `docs/` directory

[Unreleased]: https://github.com/Badr1600/p6-analyzer/compare/v2.4.2...HEAD
[2.4.2]: https://github.com/Badr1600/p6-analyzer/compare/v2.4.1...v2.4.2
[2.4.1]: https://github.com/Badr1600/p6-analyzer/compare/v2.4.0...v2.4.1
[2.4.0]: https://github.com/Badr1600/p6-analyzer/compare/v2.3.0...v2.4.0
[2.3.0]: https://github.com/Badr1600/p6-analyzer/compare/v2.2.0...v2.3.0
[2.2.0]: https://github.com/Badr1600/p6-analyzer/compare/v2.1.1...v2.2.0
[2.1.1]: https://github.com/Badr1600/p6-analyzer/compare/v2.1.0...v2.1.1
[2.1.0]: https://github.com/Badr1600/p6-analyzer/compare/v2.0.0...v2.1.0
[2.0.0]: https://github.com/Badr1600/p6-analyzer/compare/v1.2.0...v2.0.0
[1.2.0]: https://github.com/Badr1600/p6-analyzer/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/Badr1600/p6-analyzer/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Badr1600/p6-analyzer/releases/tag/v1.0.0
