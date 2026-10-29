# Report Generation

## ReportGenerator

Generates a comprehensive report covering all major analysis dimensions.

```php
$report = $analyzer->report('2024-01-15');
```

### Output Structure

```php
[
    'generated_at' => '2024-01-15T12:00:00+00:00',
    'data_date' => '2024-01-15',
    'project_info' => ['id', 'short_name', 'name'],
    'progress' => [...],          // ProgressSummarizer output
    'health' => [...],            // ScheduleHealthChecker output
    'float_distribution' => [...], // FloatAnalyzer output
    'critical_path' => [...],     // CriticalPathCalculator output
    'work_packages' => [...],     // WorkPackageSummarizer output
]
```

`progress` and `work_packages` are what `progress()` and `workPackages()` return; see
[Progress Summary](analysis.md#progress-summary-progresssummarizer) and
[Work Packages](analysis.md#work-packages-workpackagesummarizer). Every `work_packages` item carries the `wbs_id` of its
WBS node, and `progress` resolves each activity's percent complete from its status and `complete_pct_type` (a Completed
activity counts as 100%, a Not Started one as 0%), the same way the earned value calculation does.

### Standalone Usage

```php
use TeckFuture\P6Analyzer\Analyzers\ReportGenerator;
use TeckFuture\P6Analyzer\Analyzers\ScheduleHealthChecker;
use TeckFuture\P6Analyzer\Analyzers\CriticalPathCalculator;
use TeckFuture\P6Analyzer\Analyzers\FloatAnalyzer;
use TeckFuture\P6Analyzer\Analyzers\ProgressSummarizer;
use TeckFuture\P6Analyzer\Analyzers\WorkPackageSummarizer;

$generator = new ReportGenerator(
    new ScheduleHealthChecker(),
    new CriticalPathCalculator(),
    new FloatAnalyzer(),
    new ProgressSummarizer(),
    new WorkPackageSummarizer(),
);

$report = $generator->generate($parsedData, '2024-01-15');
```
