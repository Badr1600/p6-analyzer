# Schedule Comparison

## SnapshotComparer

Compares two parsed XER schedules to identify changes. Activities are matched by `task_code`, the stable business
identifier that survives re-imports. An activity with an empty `task_code` has nothing to be matched on, so it is
skipped in both schedules: it is never reported as added, deleted or modified, and it does not count towards
`total_changes`.

```php
$result = $analyzer->compareTo($baselineData);
```

### Output Structure

```php
[
    'added_activities' => ['NEW_TASK_CODE_1'],
    'deleted_activities' => ['OLD_TASK_CODE_1'],
    'modified_activities' => [
        'A1000' => [
            'status' => ['from' => 'Not Started', 'to' => 'In Progress'],
            'duration' => ['from' => 80, 'to' => 120],
            'start_date' => ['from' => '2024-01-15', 'to' => '2024-01-22'],
        ],
    ],
    'total_changes' => 3,
]
```

### Comparing Two Files

```php
use TeckFuture\P6Analyzer\P6AnalyzerManager;
use TeckFuture\P6Analyzer\IO\XerParser;

$analyzer = new P6AnalyzerManager();
$analyzer->load('/path/to/current.xer');

$baseline = (new XerParser())->parseFile('/path/to/baseline.xer');
$diff = $analyzer->compareTo($baseline);

echo "Total changes: " . $diff['total_changes'];
```
