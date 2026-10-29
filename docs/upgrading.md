# Upgrading

- [Upgrading from 2.x to 3.0](#upgrading-from-2x-to-30)
- [Upgrading from 1.x to 2.x](#upgrading-from-1x-to-2x)

## Upgrading from 2.x to 3.0

Version 3.0 makes the analyzers agree with each other and with P6, hardens reading and writing XER and CSV
files, and renames result keys to say what they hold. The requirements are unchanged: PHP 8.3 or newer and
Laravel 12 or 13. 2.x no longer receives security fixes (see [SECURITY.md](../SECURITY.md)).

```bash
composer require teckfuture/laravel-p6-analyzer:^3.0
```

The sections below are ordered by how likely an application is to run into them. Each one says what changed,
who is affected and what to do. As in the rest of the documentation, the snippets assume `$analyzer` is a
`P6AnalyzerManager` with a schedule loaded. The figures quoted from a real schedule come from the package's
example schedule, `tests/fixtures/example_xer_file.xer` in the repository.

| # | Change | You are affected if your code |
|---|---|---|
| 1 | [A new manager for every facade call](#1-a-new-manager-for-every-facade-call) | loads through the facade or the container in one statement and analyzes in another |
| 2 | [The parser rejects input that is not XER](#2-the-parser-rejects-input-that-is-not-xer) | checks parsed data for emptiness, parses an empty string, or loads from a pipe |
| 3 | [Parsed values are UTF-8](#3-parsed-values-are-utf-8) | converts parsed values from Windows-1252 itself |
| 4 | [XER exports are Windows-1252](#4-xer-exports-are-windows-1252) | uses the `exportXer()` string as UTF-8 text |
| 5 | [CSV cells that look like formulas get a quote](#5-csv-cells-that-look-like-formulas-get-a-quote) | reads `exportCsv()` output with a program |
| 6 | [Renamed result keys](#6-renamed-result-keys) | reads schedule variance rows or the `out_of_sequence` health keys |
| 7 | [`coverage_pct` is a percentage from 0 to 100](#7-coverage_pct-is-a-percentage-from-0-to-100) | reads the earned value `coverage` block |
| 8 | [Unknown EVM method names throw](#8-unknown-evm-method-names-throw) | passes a method as a string, or runs with a changed `evm.default_method` |
| 9 | [`missing_logic` follows the DCMA rule](#9-missing_logic-follows-the-dcma-rule) | stores, charts or alerts on missing logic or DCMA points 1 to 4 |
| 10 | [Over-allocations are reported per resource per day](#10-over-allocations-are-reported-per-resource-per-day) | reads `over_allocations` |
| 11 | [`ActivityData::$activity_id` is removed](#11-activitydataactivity_id-is-removed) | constructs `ActivityData` or reads `activity_id` |
| 12 | [`addActivity()` rejects a duplicate `task_id`](#12-addactivity-rejects-a-duplicate-task_id) | adds activities that may already exist |
| 13 | [`exportToFile()` replaces the file atomically](#13-exporttofile-replaces-the-file-atomically) | exports to `php://output`, or expects an exception when a write fails |

### 1. A new manager for every facade call

2.x registered `P6AnalyzerManager` as a container singleton and the `P6Analyzer` facade cached it, so a
schedule loaded by one call stayed loaded for the rest of the process: every later facade call saw it, and in
a queue worker so did the next job. 3.0 binds the manager instead of sharing it, and every static facade call
resolves a new, empty manager. `app(P6AnalyzerManager::class)`, `app('p6-analyzer')` and constructor injection
also give a new manager each time.

**Who is affected:** code that loads a schedule in one statement and analyzes it in another, such as
`P6Analyzer::load($path);` followed by `P6Analyzer::criticalPath();`, and code that loads through one resolved
or injected manager and reads through another. The second call now throws a `RuntimeException`: "No XER data
loaded. Call load(), parse(), or fromData() first." Chained calls, which is what the documentation shows, work
as before.

**What to do:** keep the manager that `load()`, `parse()` or `fromData()` returns, and hand it, or the data
from its `getData()`, to the code that needs it:

```php
use TeckFuture\P6Analyzer\Facades\P6Analyzer;
use TeckFuture\P6Analyzer\P6AnalyzerManager;

// 2.x: P6Analyzer::load('/path/to/schedule.xer');
//      $cpm = P6Analyzer::criticalPath();
$analyzer = P6Analyzer::load('/path/to/schedule.xer');
$cpm = $analyzer->criticalPath();
$dcma = $analyzer->dcma14Point('2024-01-15');

// Elsewhere, start a manager from the parsed data instead of expecting a shared one
$report = app(P6AnalyzerManager::class)->fromData($analyzer->getData())->report('2024-01-15');
```

### 2. The parser rejects input that is not XER

2.x returned an empty `ParsedXerData` for content with no `ERMHDR` line and no tables (an empty string, a file
that is not XER) and, after a PHP notice, for a path that is a directory. 3.0 throws `XerParseException`
instead, from `XerParser::parse()` and `parseFile()`, and so from `load()` and `parse()` on the manager and the
facade. `parseFile()` reads regular files only: a named pipe, a process substitution (`<(...)`) or `/dev/stdin`
when it is a pipe, which 2.x read, throws as well. A file that cannot be read throws it without a PHP warning
first. Content that has tables but no `ERMHDR` line, or a header but no tables, is still parsed.

**Who is affected:** code that validates an uploaded file by parsing it and checking for empty data, code that
parses an empty string to start an empty schedule, and code that passes a pipe, such as `/dev/stdin` or
`<(...)`, to `load()`.

**What to do:** catch `XerParseException` (it extends `RuntimeException`). Read a pipe yourself and pass the
string to `parse()`. A file with a header and no tables still parses, so keep an emptiness check if you need
activities:

```php
use TeckFuture\P6Analyzer\Exceptions\XerParseException;
use TeckFuture\P6Analyzer\P6AnalyzerManager;

function loadSchedule(string $path): ?P6AnalyzerManager
{
    try {
        $analyzer = (new P6AnalyzerManager)->load($path);
    } catch (XerParseException) {
        return null; // not a readable file, or not XER content
    }

    return $analyzer->getData()->getActivities()->isEmpty() ? null : $analyzer;
}
```

To start from an empty schedule, construct the data instead of parsing an empty string:

```php
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;

// 2.x: $data = (new XerParser)->parse('');
$data = new ParsedXerData(header: '', tables: collect());
```

### 3. Parsed values are UTF-8

P6 writes XER files as Windows-1252. 2.x kept the file's bytes, so `£` or `é` came out as a byte that is not
valid UTF-8. 3.0 converts content that is not valid UTF-8 from Windows-1252, and drops a leading UTF-8 byte
order mark, so every parsed value is UTF-8. Content that is already valid UTF-8 is left as it is.

**Who is affected:** code that converted parsed values itself, with `mb_convert_encoding()`, `iconv()` or
`utf8_encode()`. A second conversion garbles the text: `£` becomes `Â£`. Converting the whole file to UTF-8
before parsing it still works, because valid UTF-8 is left alone.

**What to do:** remove your own conversion.

```php
$pound = $analyzer->getData()->getTable('CURRTYPE')->firstWhere('curr_type', 'British Pound');

// 2.x: $symbol = mb_convert_encoding($pound['curr_symbol'], 'UTF-8', 'Windows-1252');
$symbol = $pound['curr_symbol']; // '£'
```

### 4. XER exports are Windows-1252

`XerExporter::export()` and the manager's `exportXer()` convert the values back to Windows-1252, the encoding
P6 reads. A character Windows-1252 has no byte for, such as a Greek or Chinese letter, is written as `?`. 2.x
wrote the bytes it held, so the output had the encoding of the input file, plus any UTF-8 text your code had
set through `ScheduleUpdater`.

**Who is affected:** for a Windows-1252 file from P6, the encoding of the export does not change. The change
shows when the input was UTF-8 or your code set text that is not ASCII: that text is now written as
Windows-1252. Code that uses the export as UTF-8 text, passing it to `json_encode()`, a UTF-8 database column
or a text response, must convert it first. Writing it to a file for P6, which is what `exportXerToFile()` does,
needs no change.

**What to do:**

```php
$xer = $analyzer->exportXer(); // Windows-1252, ready for P6

// Convert it before you use it as UTF-8 text
$text = mb_convert_encoding($xer, 'UTF-8', 'Windows-1252');
$json = json_encode(['xer' => $text]);
```

### 5. CSV cells that look like formulas get a quote

`CsvExporter` and the manager's `exportCsv()` put a single quote in front of a string cell that starts with
`=`, `+`, `-`, `@`, a tab or a carriage return, so a spreadsheet shows it as text instead of running it as a
formula. Header cells are covered, and numeric strings such as `-16.0` and `+5` are left alone. 2.x wrote every
cell as it was. The output also follows RFC 4180 now: a double quote is doubled and a backslash is never an
escape character.

**Who is affected:** programs that read the CSV, such as an import into another system or a test that compares
the output. The quote becomes part of the cell, and P6 data has such cells even without free text: the `guid`
and `tmpl_guid` columns are base64 and can start with `+`, so `+s9xHEc4cW1Cd4fsQU8RLQ` is written as
`'+s9xHEc4cW1Cd4fsQU8RLQ`.

**What to do:** keep `exportCsv()` for files people open in a spreadsheet. It always applies the guard, so for
a program, use the exporter with the guard turned off:

```php
use TeckFuture\P6Analyzer\IO\CsvExporter;

$csv = $analyzer->exportCsv('TASK'); // guarded, for a spreadsheet

$raw = (new CsvExporter(escapeFormulas: false))->export($analyzer->getData()->getTable('TASK'));
```

### 6. Renamed result keys

The schedule variance rows now use P6's terms, baseline and current, and one health check is named after what
it actually checks.

Each entry of `slipping_activities`, from `scheduleVariance()` and `ScheduleVarianceCalculator::calculate()`:

| 2.x | 3.0 |
|---|---|
| `master_start` | `baseline_start` |
| `master_finish` | `baseline_finish` |
| `workbench_start` | `current_start` |
| `workbench_finish` | `current_finish` |

The argument of `scheduleVariance()` is `$baselineData` instead of `$masterData`, on the manager and the facade.
Only a call that names the argument (`masterData: $baseline`) needs a change.

The result of `scheduleHealth()` and `ScheduleHealthChecker::check()`, and so `report()['health']`:

| 2.x | 3.0 |
|---|---|
| `out_of_sequence` | `actual_start_after_data_date` |
| `out_of_sequence_activities` | `actual_start_after_data_date_activities` |

The check is unchanged: it counts the activities whose actual start is after the data date. It does not detect
P6's out-of-sequence progress, which the old name suggested.

**Who is affected:** code that reads these keys, including views, API resources and stored results. An old key
now reads as `null` with an "Undefined array key" warning, which a Laravel application turns into an
`ErrorException`.

**What to do:** rename the keys.

```php
use TeckFuture\P6Analyzer\IO\XerParser;

$baseline = (new XerParser)->parseFile('/path/to/baseline.xer');
$variance = $analyzer->scheduleVariance(baselineData: $baseline);

foreach ($variance['slipping_activities'] as $activity) {
    // 2.x: master_finish and workbench_finish
    $finishes = [$activity['baseline_finish'], $activity['current_finish']];
}

$health = $analyzer->scheduleHealth('2024-01-15');
$startsAfterDataDate = $health['actual_start_after_data_date']; // 2.x: out_of_sequence
```

### 7. `coverage_pct` is a percentage from 0 to 100

`coverage.coverage_pct` was a ratio from 0 to 1 rounded to two decimals (`0.72`), unlike every other percentage
in the package. It is now a percentage from 0 to 100 with two decimals (`71.61`), in `earnedValue()`, in the
`by_cost`, `by_labor_units` and `by_duration` blocks of `earnedValueReport()`, and in `EarnedValueCalculator`.

**Who is affected:** code that multiplies `coverage_pct` by 100 to show it, compares it with a value from 0 to
1, or stores it.

**What to do:**

```php
$evm = $analyzer->earnedValue('2024-01-15');
$coverage = $evm['coverage']['coverage_pct']; // 71.61 (2.x: 0.72)

$label = $coverage.'%';         // 2.x: ($coverage * 100).'%'
$wellCovered = $coverage >= 50; // 2.x: $coverage >= 0.5
```

Values you stored from 2.x are on the 0 to 1 scale: multiply them by 100 before comparing them with 3.0 values.
`evm.auto_coverage_threshold` keeps its 0 to 1 scale (`0.5` is half), so leave your configured value as it is.

### 8. Unknown EVM method names throw

2.x turned any method string it did not recognize into `auto`, so `earnedValue($date, 'Cost')`, or a typo in
`evm.default_method`, quietly ran on a different basis from the one asked for. 3.0 throws an
`InvalidArgumentException` that names the value and lists the valid ones. This applies to the `$method`
argument of `earnedValue()`, to `EvmMethod::fromString()`, where a `$default` no longer rescues an unknown
string, and to the `evm.default_method` value that `earnedValue()` falls back to. `null` and `''` are still
accepted: `fromString()` returns its default for both, and `earnedValue()` reads `null` as the configured
`evm.default_method` and `''` as `auto`.

**Who is affected:** code that passes a method with a different case or spelling (`'Cost'`, `'labour_units'`),
code that forwards user input without validating it, and a published `config/p6-analyzer.php` whose
`evm.default_method` is not one of the four values. With a bad configured value, every `earnedValue()` call
without a method throws.

**What to do:** pass an `EvmMethod` case or one of the exact values `'cost'`, `'labor_units'`, `'duration'` and
`'auto'`. Validate a method that comes from a request, for example with Laravel's `Rule::enum(EvmMethod::class)`,
which accepts exactly those values.

```php
use TeckFuture\P6Analyzer\Enums\EvmMethod;

$evm = $analyzer->earnedValue('2024-01-15', EvmMethod::LaborUnits); // or 'labor_units'

try {
    $evm = $analyzer->earnedValue('2024-01-15', 'Cost');
} catch (\InvalidArgumentException $e) {
    // Unknown EVM method "Cost". Valid values: cost, labor_units, duration, auto.
}
```

### 9. `missing_logic` follows the DCMA rule

2.x counted an activity as missing logic only when it had no relationship at all, so an open end, an activity
with a predecessor and no successor, was never reported. Both checks counted WBS summaries, and the health
check also counted completed activities; both exempted every finish milestone and looked for `TT_StartMile`, a
value P6 never writes, instead of the start milestone type `TT_Mile`. Through the manager, DCMA point 1 was
never assessed at all, because `dcma14Point()` did not pass the relationships.

3.0 applies one rule, the DCMA one, to `scheduleHealth()` and DCMA point 1: an activity that is not complete and
has no predecessor or no successor. WBS summaries (`TT_WBS`) are never examined, a start milestone (`TT_Mile`)
needs only a successor and a finish milestone (`TT_FinMile`) only a predecessor. `dcma14Point()` passes the
relationships, so points 1 to 4 (logic, leads, lags, relationship types) are assessed, where 2.x reported them
as not assessable. Point 1's percentage is measured against the activities the rule examines, the incomplete
ones except WBS summaries, and its `threshold_label` reads "≤ 5% missing a predecessor or successor".
`overall_score` does not change: it comes from the float, duration and date checks only.

**Who is affected:** anyone who stores, charts or alerts on `missing_logic`, `missing_logic_activities` or
DCMA points 1 to 4. No code change is needed, but the numbers move, in either direction: open ends are now
counted, while completed activities and WBS summaries are not. On the package's example schedule
`missing_logic` goes from 11 to 6.

**What to do:** re-baseline stored history and thresholds. The drill-down list shows what the rule flags, and
DCMA point 1 always agrees with the health check:

```php
$health = $analyzer->scheduleHealth('2024-01-15');
$logic = $analyzer->dcma14Point('2024-01-15')['points'][0]; // DCMA point 1

$sameCount = $logic['count'] === $health['missing_logic']; // true: one rule

foreach ($health['missing_logic_activities'] as $activity) {
    // ['id', 'task_code', 'name'], at most details_limit (200) entries
}
```

### 10. Over-allocations are reported per resource per day

2.x compared each assignment line with the daily limit on its own: two overlapping 6-hour assignments (12 hours
in a day) were never flagged, and several lines over the limit on one day gave one entry each. 3.0 adds up all
of a resource's (or role bucket's) lines per calendar day and compares the total with the limit
(`resource_overallocation_daily_limit`, or the `$dailyLimit` argument). Each entry of `over_allocations` is one
resource-day:

| Key | 2.x | 3.0 |
|---|---|---|
| `qty_per_day` | the hours of one assignment line | the day's total across all lines |
| `activity` | that line's activity | the first contributing activity |
| `activities` | not present | every contributing activity, each with its own `qty_per_day` |

`resource`, `date` and, for a role bucket, `kind` and `label` are unchanged. The entries are now grouped by
resource and sorted by date within each resource; 2.x listed them in the order of the assignment lines. On the
package's example schedule `over_allocations` goes from 51 entries to 132.

**Who is affected:** code that reads `qty_per_day` as one assignment's hours, groups entries by `activity`,
counts entries, or relies on their order.

**What to do:** read the contributors from `activities`.

```php
$utilization = $analyzer->resourceUtilization('week');

foreach ($utilization['over_allocations'] as $day) {
    $total = $day['qty_per_day']; // all of the resource's hours that day

    foreach ($day['activities'] as $line) {
        $hours[$day['resource']][$day['date']][$line['activity']] = $line['qty_per_day'];
    }
}
```

`activity` is kept for code written before 3.0.0 and holds the first contributor only.

### 11. `ActivityData::$activity_id` is removed

`ActivityData` had an `activity_id` property that only an application's own models could fill. XER rows have no
such column (they identify an activity by `task_id` and `task_code`), so it was always `null` for imported
data. The property is gone, and with it the constructor's second parameter. `fromModelOrArray()` no longer
reads an `activity_id` key or attribute.

**Who is affected:** code that constructs `ActivityData` with positional arguments or with the named argument
`activity_id:`, and code that reads `$activity->activity_id`. A positional call written for 2.x hands every
argument after `$id` to the parameter before the one it was meant for, and PHP throws a `TypeError`. Reading the
property gives an "Undefined property" warning, an `ErrorException` in a Laravel application.

**What to do:** drop the second argument, or build the DTO with named arguments or `fromModelOrArray()`. An array
passed without a `raw_data` key keeps all of its keys in `raw_data`, `activity_id` included; with a `raw_data`
key only that array is kept. For a model, read the value from the model.

```php
use TeckFuture\P6Analyzer\DTOs\ActivityData;

// 2.x: new ActivityData('1001', 'EXT-42', 'A1000', 'Pour slab', ...)
$activity = ActivityData::fromModelOrArray([
    'task_id' => '1001',
    'task_code' => 'A1000',
    'task_name' => 'Pour slab',
    'activity_id' => 'EXT-42',
]);

$externalId = $activity->raw_data['activity_id']; // 'EXT-42'
```

### 12. `addActivity()` rejects a duplicate `task_id`

2.x added the row even when the `TASK` table already had an activity with that `task_id`, leaving two activities
with one id. 3.0 throws `XerParseException` ("An activity with task_id 99999 already exists") and adds nothing.
A missing or empty `task_id` throws as before, while `'0'` is now accepted. A document without a `TASK` table
gets one, with the row's keys as its columns, where 2.x silently did nothing.

**Who is affected:** code that adds activities that may already be there, such as an import that can run twice.

**What to do:** check first, then update the existing activity or skip it:

```php
use TeckFuture\P6Analyzer\IO\ScheduleUpdater;

$data = $analyzer->getData();
$updater = new ScheduleUpdater();
$row = ['task_id' => '99999', 'task_code' => 'NEW01', 'task_name' => 'New Activity'];

if ($data->getTable('TASK')->containsStrict('task_id', $row['task_id'])) {
    $updater->updateActivity($data, $row['task_id'], $row);
} else {
    $updater->addActivity($data, $row);
}
```

### 13. `exportToFile()` replaces the file atomically

`XerExporter::exportToFile()` and the manager's `exportXerToFile()` write to a temporary file in the target's
directory and then move it over the target, so a failed export never leaves a half-written file. The new file
keeps the old one's permissions, and a symbolic link is followed. Three things differ from 2.x:

- Only regular files work. A stream wrapper such as `php://output`, which 2.x wrote to, returns `false`.
- The directory must be writable, not just the file. 2.x could overwrite a writable file in a read-only
  directory; 3.0 returns `false` and leaves the file as it was.
- A failure returns `false` without a PHP warning. In a Laravel application the 2.x warning surfaced as an
  `ErrorException`; now nothing is thrown, so check the return value. An empty path, which threw a
  `ValueError` in 2.x, returns `false` too.

**Who is affected:** code that streams an export through a stream wrapper such as `php://output`, writes into a
directory it cannot create files in, or relies on an exception when the write fails.

**What to do:** check the result, and write the string from `exportXer()` yourself for anything that is not a
local file:

```php
use Illuminate\Support\Facades\Storage;

if (! $analyzer->exportXerToFile('/path/to/output.xer')) {
    throw new \RuntimeException('The XER file could not be written.');
}

Storage::put('schedules/output.xer', $analyzer->exportXer());

$download = response()->streamDownload(function () use ($analyzer) {
    echo $analyzer->exportXer();
}, 'output.xer');
```

### Results that change without a code change

These fixes need no change to your code, but they move numbers you may have stored:

- **DCMA points 1 to 4** are assessed through `dcma14Point()` and the facade, as described in section 9.
- **Schedule variance** counts whole calendar days and ignores the time of day. A finish moved 12 hours later
  on the same day is 0, where 2.x counted 1, and a finish on the next date is 1 even when it is only a few hours
  later, where 2.x could count 0. `variance_days`, `start_variance_days` and the list of slipping activities can
  change.
- **Work packages** are keyed by `wbs_id`. Two WBS nodes with the same name are two packages, where 2.x merged
  them into one with the counts combined. Every item of `workPackages()` and `report()['work_packages']` has a
  new `wbs_id` key.
- **`progress()`** resolves each activity's percent complete the way earned value does: a Completed activity
  counts as 100% and a Not Started one as 0%, whatever their percent fields say, and `complete_pct_type` picks
  the measure. 2.x used the physical percent as it was.
- **`compareTo()`** skips activities with an empty `task_code` in both schedules. 2.x put them all on one empty
  key and compared or reported only the last of them.
- **Resource utilization** keeps the last day of an assignment that ends earlier in the day than it started.
- **XER exports** end with the `%E` line P6 writes, and a table's `%F` line lists every column any of its rows
  has, so a column that `updateActivity()` adds to one activity is kept.

### Configuration

No configuration change is required. Two keys are new: `hours_per_day` (default `8`), which converts hours to
days for the float distribution buckets and the day counts in the DCMA labels, and `min_slip_days` (default
`0`), which the schedule variance check already read. A published `config/p6-analyzer.php` without them gets
the defaults, so add them only to change them. Check `evm.default_method` as described in section 8.

See the [3.0.0 entry in the CHANGELOG](../CHANGELOG.md) for the full list of changes.

## Upgrading from 1.x to 2.x

Version 2.0 rebuilt earned value management (EVM) around explicit measurement methods. Code that
calls `earnedValue()` or `EarnedValueCalculator` needs the checks below; nothing else changed.

### 1. Renamed result keys

| 1.x key | 2.x replacement |
|---|---|
| `source` | `method` (`cost`, `labor_units` or `duration`) and `unit` |
| `has_cost_data`, `cost_data_coverage` | `coverage` (measurable activities vs activities that carry data) |
| `duration_fallback_count` | `actual_cost_sources` (tracked / inferred / status / none counts) |

### 2. `cpi`, `spi` and `tcpi` can be `null`

When a denominator is zero these indices are now `null` instead of a fake `1.0`. Handle `null`
before formatting or comparing them.

### 3. One measurement method per calculation

1.x fell back from cost to duration activity by activity. 2.x applies one method to the whole
calculation, and activities without data for that method are counted in `coverage` instead.
If you relied on a specific basis, pass it explicitly:

```php
use TeckFuture\P6Analyzer\Enums\EvmMethod;

$evm = $analyzer->earnedValue('2024-01-15', EvmMethod::Cost);
```

Without a method, `auto` (config `p6-analyzer.evm.default_method`) picks the best-covered method
for each project.

### 4. Calendar-hours actual cost removed

Duration-based actual cost no longer counts calendar hours (nights and weekends) against work-hour
budgets. The duration method now infers actual cost from `remain_drtn_hr_cnt` and activity status.

### 5. New `evm` configuration block

If you published `config/p6-analyzer.php`, copy the `evm` block from the package's config file
(`default_method`, `auto_coverage_threshold`, `infer_actuals_from_remaining`,
`respect_complete_pct_type`, `status_overrides_percent`). Re-publishing with `--force` also works,
but it overwrites your own changes:

```bash
php artisan vendor:publish --tag=p6-analyzer-config --force
```

See the [2.0.0 entry in the CHANGELOG](../CHANGELOG.md) for the full list of changes.
