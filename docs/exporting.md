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

The output follows RFC 4180: a double quote inside a value is doubled, and a value with a comma, a quote or a
line break is enclosed in double quotes. Nothing is backslash-escaped.

### Formula Guard

A spreadsheet runs a cell that starts with `=`, `+`, `-` or `@` as a formula, and some do the same for a cell
that starts with a tab or a carriage return. Activity names, notes and other free text in a schedule come from
many people, so opening an exported CSV in Excel or LibreOffice could run a formula that one of them typed.
The exporter therefore puts a single quote in front of such a cell, so the spreadsheet reads it as text:

| Cell | Written as |
|---|---|
| `=1+1` | `'=1+1` |
| `@SUM(A1:A9)` | `'@SUM(A1:A9)` |
| `-16.0`, `+5` | unchanged, they are numeric |
| `Phase 2 - civil`, `user@example.com` | unchanged, they do not start with one of the characters |

The guard covers the header row as well. Only strings are touched, and a numeric string such as `-16.0` stays
as it is, so negative numbers keep their sign.

The quote becomes part of the cell: it is visible in the spreadsheet and present when a program reads the CSV.
For a program that reads the CSV rather than a spreadsheet, turn the guard off:

```php
$exporter = new CsvExporter(escapeFormulas: false);
$csv = $exporter->export($data->getTable('TASK'));
```

`$analyzer->exportCsv()` always applies the guard. To get raw values from the manager's data, use the exporter
directly: `(new CsvExporter(escapeFormulas: false))->export($analyzer->getData()->getTable('TASK'))`.
