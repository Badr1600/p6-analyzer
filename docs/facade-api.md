# Facade & Manager API Reference

## P6AnalyzerManager

The central class for all operations. Can be used standalone (without Laravel) or via the Facade.

### Loading Data

| Method | Returns | Description |
|---|---|---|
| `load(string $filePath)` | `self` | Parse an XER file |
| `parse(string $content)` | `self` | Parse raw XER string |
| `fromData(ParsedXerData $data)` | `self` | Use pre-parsed data |
| `getData()` | `ParsedXerData` | Get the underlying data |

### Analysis Methods

| Method | Returns | Analyzer |
|---|---|---|
| `criticalPath(?float $threshold)` | `array` | `CriticalPathCalculator` |
| `floatAnalysis()` | `array` | `FloatAnalyzer` |
| `dcma14Point(?string $dataDate)` | `array` | `DCMA14PointChecker` |
| `earnedValue(?string $dataDate)` | `array` | `EarnedValueCalculator` |
| `relationshipAnalysis()` | `array` | `RelationshipAnalyzer` |
| `scheduleVariance(ParsedXerData $master)` | `array` | `ScheduleVarianceCalculator` |
| `scheduleHealth(?string $dataDate)` | `array` | `ScheduleHealthChecker` |
| `resourceUtilization(string $period, ?float $dailyLimit)` | `array` | `ResourceUtilizationAnalyzer` |
| `wbsAnalysis()` | `array` | `WbsAnalyzer` |
| `lookahead(string $dataDate, int $weeks)` | `array` | `LookaheadGenerator` |
| `workPackages()` | `array` | `WorkPackageSummarizer` |
| `progress()` | `array` | `ProgressSummarizer` |
| `report(?string $dataDate)` | `array` | `ReportGenerator` |
| `compareTo(ParsedXerData $baseline)` | `array` | `SnapshotComparer` |

### IO Methods

| Method | Returns | Description |
|---|---|---|
| `query()` | `ScheduleQueryEngine` | Get query engine |
| `updater()` | `ScheduleUpdater` | Get updater |
| `exportXer()` | `string` | Export to XER string |
| `exportXerToFile(string $path)` | `bool` | Export to XER file |
| `exportCsv(string $tableName)` | `string` | Export table to CSV |

## P6Analyzer Facade

The `P6Analyzer` facade proxies all `P6AnalyzerManager` methods:

```php
use TeckFuture\P6Analyzer\Facades\P6Analyzer;

P6Analyzer::load('/path/to/file.xer')->criticalPath();
```
