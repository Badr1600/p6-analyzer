# Querying Schedule Data

## ScheduleQueryEngine

The `ScheduleQueryEngine` provides methods for searching and filtering parsed XER data.

```php
use TeckFuture\P6Analyzer\IO\ScheduleQueryEngine;

$engine = new ScheduleQueryEngine($parsedData);
```

Or via the Manager:

```php
$engine = $analyzer->query();
```

### List Projects

```php
$projects = $engine->listProjects(); // Collection<ProjectData>
```

### List Activities

```php
$all = $engine->listActivities();
$filtered = $engine->listActivities('42'); // Filter by proj_id
```

### Get Single Activity

```php
$activity = $engine->getActivity('A1000'); // By task_id or task_code
// Returns null if not found
```

### Search Activities

```php
$results = $engine->searchActivities('concrete'); // Case-insensitive partial match
```

### List Resources & Calendars

```php
$resources = $engine->listResources();   // Raw RSRC table
$calendars = $engine->listCalendars();   // Raw CALENDAR table
```
