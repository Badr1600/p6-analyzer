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
