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
