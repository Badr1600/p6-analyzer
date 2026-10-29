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

A key that is not yet a column of the `TASK` table is added to that activity's row. `XerExporter` writes it as a
column for every row, with an empty value for the other activities.

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
```

The rules:

- **`task_id` is required.** A missing or empty `task_id` throws `XerParseException`. Any other value is valid,
  including `'0'`.
- **`task_id` must be new.** If the `TASK` table already has an activity with that `task_id`, `addActivity()`
  throws `XerParseException` and adds nothing. Catch `XerParseException` to handle both problems.
- **The new row gets the existing columns.** It has the columns of the rows already in the `TASK` table: a key
  that is not one of them is ignored, and a column you leave out is an empty string.
- **The `TASK` table is created when needed.** If the data has no `TASK` table, or the table has no rows,
  `addActivity()` creates it with the row you pass, and that row's keys become its columns.

```php
use TeckFuture\P6Analyzer\Exceptions\XerParseException;

try {
    $updater->addActivity($data, ['task_id' => '99999', 'task_name' => 'New Activity']);
} catch (XerParseException $e) {
    // "New activity must have a task_id" or "An activity with task_id 99999 already exists"
}
```

### Via the Manager

```php
$updater = $analyzer->updater();
$updater->updateActivity($analyzer->getData(), '12345', ['status_code' => 'TK_Complete']);
$analyzer->exportXerToFile('/path/to/updated.xer');
```
