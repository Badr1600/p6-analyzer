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
| `criticalPath(?float $threshold = null)` | `array` | `CriticalPathCalculator` |
| `floatAnalysis()` | `array` | `FloatAnalyzer` |
| `dcma14Point(?string $dataDate = null)` | `array` | `DCMA14PointChecker` |
| `earnedValue(?string $dataDate = null, EvmMethod\|string\|null $method = null)` | `array` | `EarnedValueCalculator` |
| `earnedValueReport(?string $dataDate = null)` | `array` | `EarnedValueCalculator` |
| `relationshipAnalysis()` | `array` | `RelationshipAnalyzer` |
| `scheduleVariance(ParsedXerData $masterData)` | `array` | `ScheduleVarianceCalculator` |
| `scheduleHealth(?string $dataDate = null)` | `array` | `ScheduleHealthChecker` |
| `resourceUtilization(string $period = 'week', ?float $dailyLimit = null, ?int $weekStartsOn = null, array $roleLabels = [])` | `array` | `ResourceUtilizationAnalyzer` |
| `wbsAnalysis()` | `array` | `WbsAnalyzer` |
| `lookahead(string $dataDate, int $weeks = 3)` | `array` | `LookaheadGenerator` |
| `workPackages()` | `array` | `WorkPackageSummarizer` |
| `progress()` | `array` | `ProgressSummarizer` |
| `report(?string $dataDate = null)` | `array` | `ReportGenerator` |
| `compareTo(ParsedXerData $baselineData)` | `array` | `SnapshotComparer` |

### IO Methods

| Method | Returns | Description |
|---|---|---|
| `query()` | `ScheduleQueryEngine` | Get query engine |
| `updater()` | `ScheduleUpdater` | Get updater |
| `exportXer()` | `string` | Export to XER string |
| `exportXerToFile(string $filePath)` | `bool` | Export to XER file |
| `exportCsv(string $tableName)` | `string` | Export table to CSV |

## P6Analyzer Facade

The `P6Analyzer` facade proxies all `P6AnalyzerManager` methods:

```php
use TeckFuture\P6Analyzer\Facades\P6Analyzer;

P6Analyzer::load('/path/to/file.xer')->criticalPath();
```

### Chain from `load()`, `parse()` or `fromData()`

A manager holds the XER data it was loaded with, so every static call resolves a **new** manager from the container and nothing is kept between separate static calls. Start each analysis with `load()`, `parse()` or `fromData()` and chain the rest onto its result:

```php
// One chain, one manager
$dcma = P6Analyzer::load('/path/to/file.xer')->dcma14Point('2024-01-15');

// A separate static call gets a new manager that has no data
P6Analyzer::load('/path/to/file.xer');

try {
    P6Analyzer::criticalPath();
} catch (\RuntimeException $e) {
    // "No XER data loaded. Call load(), parse(), or fromData() first."
}
```

To run several analyses on one file, keep the manager that `load()` returns:

```php
$analyzer = P6Analyzer::load('/path/to/file.xer');

$cpm = $analyzer->criticalPath();
$dcma = $analyzer->dcma14Point('2024-01-15');
```

The container behaves the same way: `app(P6AnalyzerManager::class)` and `app('p6-analyzer')` return a new manager each time they are resolved.
