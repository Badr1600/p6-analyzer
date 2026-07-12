# Parsing XER Files

## XerParser

The `XerParser` class reads Primavera P6 `.xer` files and converts them into a `ParsedXerData` structure.

### Parse from File

```php
use TeckFuture\P6Analyzer\IO\XerParser;

$parser = new XerParser();
$data = $parser->parseFile('/path/to/schedule.xer');
```

### Parse from String

```php
$xerContent = file_get_contents('/path/to/schedule.xer');
$data = $parser->parse($xerContent);
```

### Error Handling

```php
use TeckFuture\P6Analyzer\Exceptions\XerParseException;

try {
    $data = $parser->parseFile('/missing/file.xer');
} catch (XerParseException $e) {
    // "XER file not found at path: /missing/file.xer"
}
```

## ParsedXerData Structure

`ParsedXerData` holds:
- `header` — The ERMHDR line from the file
- `tables` — A `Collection<string, Collection<array>>` of all tables

### Accessing Raw Tables

```php
$tasks = $data->getTable('TASK');        // Collection of arrays
$wbs = $data->getTable('PROJWBS');       // Collection of arrays
$resources = $data->getTable('RSRC');     // Collection of arrays
```

### Accessing Typed DTOs

```php
$activities = $data->getActivities();    // Collection<ActivityData>
$projects = $data->getProjects();        // Collection<ProjectData>
$relationships = $data->getRelationships(); // Collection<RelationshipData>
```

## Status Code Normalization

`ActivityData` automatically normalizes P6 status codes:

| Raw P6 Code | Normalized |
|---|---|
| `TK_Complete` | `Completed` |
| `TK_Active` | `In Progress` |
| `TK_NotStart` | `Not Started` |

Already-normalized strings pass through unchanged.
