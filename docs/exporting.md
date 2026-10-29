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

### Encoding

`XerParser` reads P6's Windows-1252 files as UTF-8, and the exporter converts its output back to
Windows-1252. A file you export is therefore encoded the way P6 writes it: `é` is the single byte `0xE9`
again and `£` is `0xA3`.

- `export()` returns a Windows-1252 string, not a UTF-8 one. Write it to a file as it is. To show it as text
  or put it in JSON, convert it first with `mb_convert_encoding($xer, 'UTF-8', 'Windows-1252')`.
- A character that Windows-1252 has no byte for, such as a Greek or Chinese letter, is written as `?`.

### Writing a File

`exportToFile()` and `exportXerToFile()` return `true` once the file is written and `false` when it cannot be
written. They raise no PHP warning, so check the return value.

The content goes to a temporary file in the target's directory first and is then moved over the target. A
reader never sees a half-written file, and a failed export leaves an existing file as it was. The new file
keeps the permissions of the file it replaces, and a symbolic link is followed instead of being replaced.

The path must name a regular file, existing or new, in a writable directory. These return `false`: a
directory that does not exist or cannot be written to, a path that is a directory, a device or a pipe, a
read-only file, and a stream wrapper such as `php://output`. To send an export somewhere else, write the string
that `export()` returns yourself.

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
