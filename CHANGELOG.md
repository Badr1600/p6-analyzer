# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [Unreleased]

## [3.0.0] - 2026-10-29

Version 3.0 makes the analyzers agree with each other and with P6, hardens reading and writing XER and CSV files,
and renames result keys to say what they hold. The facade no longer shares loaded data between calls, DCMA point 1
and the health check apply one missing-logic rule, over-allocation is judged per resource per day, parsed values
are UTF-8 and XER exports are Windows-1252.
[docs/upgrading.md](https://github.com/Badr1600/p6-analyzer/blob/main/docs/upgrading.md) explains every breaking
change and how to migrate. 2.x no longer receives security fixes.

### Added
- `hours_per_day` config key (default `8`). It converts hours to days for the float distribution buckets of
  `floatAnalysis()` and the day counts in the DCMA high float and high duration labels, which assumed an 8-hour
  day. A zero, negative or non-numeric value falls back to 8.
- `min_slip_days` is in the shipped config file (default `0`). The schedule variance calculator already read it,
  but it could not be discovered or published.
- `CsvExporter` takes an `escapeFormulas` constructor argument (default `true`):
  `new CsvExporter(escapeFormulas: false)` turns off the formula guard described under Changed.
- `ActivityData::WBS_SUMMARY_TASK_TYPE` (`TT_WBS`), `START_MILESTONE_TASK_TYPE` (`TT_Mile`),
  `FINISH_MILESTONE_TASK_TYPE` (`TT_FinMile`) and `MILESTONE_TASK_TYPES`: the task types P6 writes, in one place
  for the DCMA check, the health check and earned value.
- `Support\MissingLogic`, the missing-logic rule shared by DCMA point 1 and the health check, and
  `Support\PercentComplete`, the percent complete rule shared by earned value and `progress()`.
- Each `workPackages()` item, in `report()['work_packages']` too, has a `wbs_id` key: the WBS node the package
  stands for, or `'unassigned'`.

### Changed
- **Breaking:** The `P6Analyzer` facade resolves a new `P6AnalyzerManager` for every static call, and the container
  binds the manager instead of sharing one instance, so `app(P6AnalyzerManager::class)` and constructor injection
  also give a new one each time. Data loaded by one call is no longer seen by the next: `P6Analyzer::load($path);`
  followed by `P6Analyzer::criticalPath();` throws "No XER data loaded". Chain from `load()`, `parse()` or
  `fromData()`, or keep the manager they return.
- **Breaking:** `XerParser::parse()` and `parseFile()`, and so `load()` and `parse()` on the manager and the
  facade, throw `XerParseException` for content with neither an `ERMHDR` header nor any table, such as an empty
  string or a file that is not XER. They returned empty data. `parseFile()` also throws it for a path that is not
  a regular file: a directory, a named pipe, a process substitution such as `<(...)`, or `/dev/stdin` when it is a
  pipe. 2.x read pipes, so read such input yourself and pass the string to `parse()`. Content with tables but no
  header is still accepted.
- **Breaking:** `XerParser` returns UTF-8. Content that is not valid UTF-8 is converted from Windows-1252, the
  encoding P6 writes, so values such as `£` and `é` come out right; 2.x kept the file's bytes. Content that is
  already valid UTF-8 is left as it is.
- **Breaking:** `XerExporter::export()` and `exportXer()` return Windows-1252, the encoding P6 reads, converting
  the values back, so the values of a parsed P6 file keep their bytes through an export. 2.x wrote the values'
  bytes as they were, so UTF-8 input, or text set in PHP, came out as UTF-8. A character Windows-1252 has no byte
  for is written as `?`.
- **Breaking:** `CsvExporter`, and so `exportCsv()`, puts a single quote in front of a string cell that starts
  with `=`, `+`, `-`, `@`, a tab or a carriage return, so a spreadsheet shows it as text instead of running it as
  a formula. Header cells are covered, numeric strings such as `-16.0` and `+5` are left alone, and P6 GUIDs that
  start with `+` get the quote too. `exportCsv()` always applies the guard.
- **Breaking:** Each `slipping_activities` entry of `scheduleVariance()` and
  `ScheduleVarianceCalculator::calculate()` uses `baseline_start`, `baseline_finish`, `current_start` and
  `current_finish` instead of `master_start`, `master_finish`, `workbench_start` and `workbench_finish`. The
  argument of `scheduleVariance()` is `$baselineData` instead of `$masterData`, on the manager and the facade.
- **Breaking:** `scheduleHealth()`, and so `report()['health']`, returns `actual_start_after_data_date` and
  `actual_start_after_data_date_activities` instead of `out_of_sequence` and `out_of_sequence_activities`. The
  check is unchanged: it counts the activities whose actual start is after the data date, which is not P6's
  out-of-sequence progress.
- **Breaking:** `coverage.coverage_pct` is a percentage from 0 to 100, rounded to two decimals like the package's
  other percentages, instead of a 0–1 ratio: on the example schedule `0.72` is now `71.61`. This applies to
  `earnedValue()`, the `by_cost`, `by_labor_units` and `by_duration` blocks of `earnedValueReport()`, and
  `EarnedValueCalculator`. `evm.auto_coverage_threshold` stays a 0–1 share.
- **Breaking:** An unknown EVM method string throws `InvalidArgumentException` naming the value and listing the
  valid ones (`Unknown EVM method "Cost". Valid values: cost, labor_units, duration, auto.`), whether it is passed
  to `earnedValue()` or `EvmMethod::fromString()` or set as `evm.default_method`. It fell back to the default
  without a word: `auto`, or the `$default` that `fromString()` was given. `null` and `''` are still accepted:
  `fromString()` returns its default for both, and `earnedValue()` reads `null` as the configured
  `evm.default_method` and `''` as `auto`. A method passed to `earnedValue()` still wins over the configured one.
- **Breaking:** `missing_logic` in `scheduleHealth()` and DCMA point 1 apply one rule, the DCMA one: an
  incomplete activity with no predecessor or no successor. WBS summaries (`TT_WBS`) are never examined, a start
  milestone needs only a successor and a finish milestone only a predecessor. Before, both counted only
  activities with no link at all, so an open end was never reported, and both counted WBS summaries; the health
  check also counted completed activities. On the package's example schedule (`tests/fixtures/example_xer_file.xer`
  in the repository) `missing_logic` goes from 11 to 6. Point 1's percentage is measured against the activities
  the rule examines, and its label reads "≤ 5% missing a predecessor or successor".
- **Breaking:** Each `over_allocations` entry is now one resource-day: `qty_per_day` is the day's total and the new
  `activities` key lists every contributing activity with its hours. `activity` is kept and holds the first
  contributor. The limit is compared with the rounded total that is reported. The entries are now grouped by
  resource and sorted by date within each resource; 2.x listed them in the order of the assignment lines.
- **Breaking:** `ScheduleUpdater::addActivity()` throws `XerParseException`, and adds nothing, when an activity
  with the same `task_id` exists. It used to add a duplicate.
- **Breaking:** `XerExporter::exportToFile()` and `exportXerToFile()` write a temporary file next to the target
  and move it over the target, so a failed export leaves an existing file as it was instead of truncating it. The
  directory must be writable, a stream wrapper such as `php://output` no longer works (write the string from
  `export()` yourself), and a failure returns `false` without a PHP warning. An empty path returns `false` too,
  where 2.x threw a `ValueError`. The new file keeps the old one's permissions, a symbolic link is followed, and
  a short write counts as a failure.
- `XerExporter` ends its output with the `%E` end marker, as P6 does, so an empty document exports as `%E`.

### Removed
- **Breaking:** `ActivityData::$activity_id`, and with it the constructor's second parameter. XER rows have no
  such column, so it was always `null` for imported data. `fromModelOrArray()` no longer reads an `activity_id`
  key or attribute; an array without a `raw_data` key keeps the key in `raw_data`.

### Fixed
- Resource over-allocation is now judged per resource per day, as the docs and config describe. The hours of all
  of a resource's (or role's) assignment lines on a day are summed and compared with the daily limit. Before,
  each line was compared on its own: two overlapping 6 h assignments (12 h in a day) were never flagged, and
  several lines over the limit on the same day produced one entry each.
- Utilization dropped the last day of an assignment that ends earlier in the day than it started.
- `dcma14Point()`, and so the facade, passes the schedule's relationships to the DCMA check, so points 1 to 4
  (logic, leads, lags, relationship types) are assessed. They always reported "Relationship data was not
  provided." A schedule without relationships is assessed against zero of them, and point 4 then reports "No
  relationships in scope." `overall_score` is unchanged.
- The missing-logic checks know P6's start milestone type, `TT_Mile`. They looked for `TT_StartMile`, which P6
  never writes, so a start milestone was examined as an ordinary activity.
- Calling the analyzers without a booted Laravel application no longer throws "Target class [config] does not
  exist." from `criticalPath()`, `dcma14Point()`, `scheduleVariance()`, `resourceUtilization()`, `earnedValue()`
  and `report()`. Config is read through `Support\Config::get()`, which falls back to the default.
- Schedule variance is measured in whole calendar days and ignores the time of day. Carbon 3's fractional day
  difference was rounded, so a finish moved 12 hours within one day counted as a one-day slip, a move from 08:00
  to 20:00 the next day as two days, and a move to the next date less than 12 hours later as none.
- `workPackages()` keeps WBS nodes that share a name apart. Packages were grouped by name, so same-named nodes
  were merged into one package with their counts combined.
- `progress()` resolves percent complete the way earned value does: a Completed activity counts as 100%, a Not
  Started one as 0%, `complete_pct_type` picks the measure, and the result is clamped to 0–100. It weighted
  every activity by its raw physical percent.
- `compareTo()` skips activities without a `task_code`, as the variance check does. They all fell onto one `''`
  key, so only the last of them on each side was compared, or reported as added or deleted.
- `XerParser` drops a leading UTF-8 byte order mark, which hid the `ERMHDR` line, and `parseFile()` raises no
  PHP warning when a read fails.
- `XerExporter` lists the columns of all of a table's rows in its `%F` line, in order of first appearance. It
  used the first row's, so a column that `ScheduleUpdater::updateActivity()` added to any activity but the first
  was lost.
- CSV output follows RFC 4180: a double quote is doubled and a backslash is never an escape character. A
  backslash before a quote no longer corrupts a value, and PHP 8.4 no longer reports a deprecation for every row.
- `ScheduleUpdater::addActivity()` accepts the `task_id` `'0'`, and creates the `TASK` table, with the row's keys
  as its columns, when the data has none. It refused `'0'`, did nothing without a `TASK` table, and gave an empty
  `TASK` table a row without columns.
- The facade's `@method` block lists every public manager method with its exact signature, and a test keeps it in
  sync. `getData()`, `earnedValueReport()`, `scheduleVariance()`, `query()`, `updater()` and `exportXerToFile()`
  were missing and `earnedValue()` lacked `$method`, so IDEs and static analysis rejected valid calls.
- The manager's `@return` shapes match what the analyzers return, and the documentation examples run: the
  schedule variance example called `getData()` on `ParsedXerData`, and the role-labels example passed `null` as
  `$roleLabels`.

## [2.4.2] - 2026-10-28

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

[Unreleased]: https://github.com/Badr1600/p6-analyzer/compare/v3.0.0...HEAD
[3.0.0]: https://github.com/Badr1600/p6-analyzer/compare/v2.4.2...v3.0.0
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
