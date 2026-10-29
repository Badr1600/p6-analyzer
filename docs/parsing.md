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

The parser is strict about its input: a problem with the path or the content is reported as an
`XerParseException`, and reading a file never raises a PHP warning.

```php
use TeckFuture\P6Analyzer\Exceptions\XerParseException;

try {
    $data = $parser->parseFile('/missing/file.xer');
} catch (XerParseException $e) {
    // "XER file not found at path: /missing/file.xer"
}
```

| Input | Result |
|---|---|
| A path that is not a regular file: it does not exist, or it is a directory | `XerParseException` ("XER file not found at path: ...") |
| A file that exists but cannot be read | `XerParseException` ("Failed to read XER file at path: ...") |
| Content with no `ERMHDR` line and no tables: an empty string, plain text, binary data | `XerParseException` |
| Content with tables but no `ERMHDR` line | Parsed, with `$data->header` set to `''` |
| An `ERMHDR` line but no tables | Parsed, with an empty `$data->tables` |

`parse()` applies the same content rules as `parseFile()`, so an empty string no longer parses to an
empty `ParsedXerData`.

### Encoding

P6 writes XER files as Windows-1252, and the parser always returns UTF-8 strings:

- A leading UTF-8 byte order mark is removed, so the `ERMHDR` line is still recognised.
- Content that is not valid UTF-8 is converted from Windows-1252 to UTF-8 before it is parsed: the
  byte `0xE9` becomes `é`, `0xA3` becomes `£` and `0x80` becomes `€`.
- Content that is already valid UTF-8 is left as it is.

The rule is only whether the bytes are valid UTF-8, and it covers the whole input. A file that mixes both
encodings is converted as Windows-1252 throughout, and a Windows-1252 file whose accented letters happen to
form a valid UTF-8 sequence (the text `Ã©`, for instance) is read as UTF-8. Neither is common in practice.

`XerExporter` converts the values back to Windows-1252 when it writes, so they keep their original bytes
through a parse and an export. See [Exporting Data](exporting.md#encoding).

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
