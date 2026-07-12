# Modifying Schedules

## ScheduleUpdater

Modifies activity data in memory. Changes are made to the `ParsedXerData` object and can be exported with `XerExporter`.

### Update a Single Activity

```php
use TeckFuture\P6Analyzer\IO\ScheduleUpdater;

$updater = new ScheduleUpdater();
$updated = $updater->updateActivity($data, '12345', [
    'status_code' => 'TK_Complete',
    'phys_complete_pct' => '100',
]);
// $updated === true if found, false otherwise
```

### Batch Update

```php
$count = $updater->batchUpdate($data, [
    ['task_id' => '123', 'updates' => ['status_code' => 'TK_Complete']],
    ['task_id' => '456', 'updates' => ['target_drtn_hr_cnt' => '40']],
]);
// $count = number of successfully updated activities
```

### Add a New Activity

```php
$updater->addActivity($data, [
    'task_id' => '99999',
    'task_code' => 'NEW01',
    'task_name' => 'New Activity',
    'status_code' => 'TK_NotStart',
]);
// Throws XerParseException if task_id is missing
```

### Via the Manager

```php
$updater = $analyzer->updater();
$updater->updateActivity($analyzer->getData(), '12345', ['status_code' => 'TK_Complete']);
$analyzer->exportXerToFile('/path/to/updated.xer');
```
