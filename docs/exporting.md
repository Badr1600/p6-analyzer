# Exporting Data

## XerExporter

Serializes a `ParsedXerData` object back to the XER file format.

```php
use TeckFuture\P6Analyzer\IO\XerExporter;

$exporter = new XerExporter();
$xerString = $exporter->export($data);
$exporter->exportToFile($data, '/path/to/output.xer');
```

Via Manager:
```php
$xer = $analyzer->exportXer();
$analyzer->exportXerToFile('/path/to/output.xer');
```

The output is laid out the way P6 writes it:

- the `ERMHDR` header line, when the data has one;
- for every table that has rows, a `%T` line, a `%F` line and one `%R` line per row;
- a final `%E` line, the end-of-file marker.

Two things to know about the tables:

- **Columns come from every row.** A table's `%F` line lists the columns of all its rows: the first row's
  columns, then each column a later row adds, in the order they first appear. A column that
  `ScheduleUpdater::updateActivity()` sets on a single activity is therefore kept, and a row without a value
  for a column gets an empty one.
- **Empty tables are omitted.** A table with no rows is not written, so it is missing when you parse the
  export again. Every table with rows comes back unchanged.

## CsvExporter

Exports any collection of rows (from a parsed table) to CSV format.

```php
use TeckFuture\P6Analyzer\IO\CsvExporter;

$exporter = new CsvExporter();
$csv = $exporter->export($data->getTable('TASK'));
$exporter->exportToFile($data->getTable('TASK'), '/path/to/tasks.csv');
```

Via Manager:
```php
$csv = $analyzer->exportCsv('TASK');
```
