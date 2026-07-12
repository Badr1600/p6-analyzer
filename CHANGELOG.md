# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
