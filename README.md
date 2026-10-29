# Laravel P6 Analyzer

[![Latest Version on Packagist](https://img.shields.io/packagist/v/teckfuture/laravel-p6-analyzer.svg)](https://packagist.org/packages/teckfuture/laravel-p6-analyzer)
[![Tests](https://github.com/Badr1600/p6-analyzer/actions/workflows/tests.yml/badge.svg)](https://github.com/Badr1600/p6-analyzer/actions/workflows/tests.yml)
[![PHP ^8.3](https://img.shields.io/badge/PHP-%5E8.3-8892BF.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

A standalone Laravel package for parsing, analyzing, and exporting **Primavera P6 XER** schedule files. Provides DCMA 14-point schedule assessments, critical path analysis, earned value calculations, resource utilization analysis, and much more — all from a fluent, developer-friendly API.

## Features

- **XER Parsing** — Parse `.xer` files or raw XER strings into a structured `ParsedXerData` object
- **DCMA 14-Point Check** — Automated schedule quality assessment with configurable thresholds
- **Critical Path Analysis** — Identify critical activities based on total float
- **Earned Value Management** — Calculate PV, EV, AC, CPI, SPI, EAC
- **Float Distribution** — Analyze total float spread across the schedule
- **Relationship Analysis** — FS/SS/FF/SF type distribution and lag metrics
- **Schedule Variance** — Compare two schedules to detect slipping activities
- **Schedule Health Check** — Missing resources, missing logic, actual starts after the data date
- **Resource Utilization** — Per-resource loading with over-allocation detection
- **WBS Analysis** — Work breakdown structure metrics
- **Lookahead Generation** — Filtered x-week lookahead schedules
- **Progress Summary** — Overall schedule progress by status and duration
- **Work Package Rollup** — Summarize activities into WBS-based work packages
- **Snapshot Comparison** — Diff two XER files to detect added/deleted/modified activities
- **XER Export** — Write modified schedules back to XER format
- **CSV Export** — Export any parsed table to CSV

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13

## Installation

```bash
composer require teckfuture/laravel-p6-analyzer
```

### Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag=p6-analyzer-config
```

## Quick Start

### Using the Facade

```php
use TeckFuture\P6Analyzer\Facades\P6Analyzer;

// Load and analyze
$report = P6Analyzer::load('/path/to/schedule.xer')->report('2024-01-15');

// Critical path only
$cpm = P6Analyzer::load('/path/to/schedule.xer')->criticalPath();

// DCMA 14-point check
$dcma = P6Analyzer::load('/path/to/schedule.xer')->dcma14Point('2024-01-15');
```

### Using the Manager Directly

```php
use TeckFuture\P6Analyzer\P6AnalyzerManager;

$analyzer = new P6AnalyzerManager();
$analyzer->load('/path/to/schedule.xer');

$health = $analyzer->scheduleHealth('2024-01-15');
$evm = $analyzer->earnedValue();
$float = $analyzer->floatAnalysis();
```

### Earned Value Management

EVM runs under an explicit measurement method — `cost` (resource-assignment
costs), `labor_units` (native TASK work quantities), or `duration` (a
last-resort proxy). Units are never mixed across activities; `auto` (the
default) picks the best-covered method per project and reports its choice.

```php
use TeckFuture\P6Analyzer\Enums\EvmMethod;

// Single method — auto-resolved or explicit
$evm = $analyzer->earnedValue('2024-01-15');
$evm = $analyzer->earnedValue('2024-01-15', EvmMethod::LaborUnits);

$evm['cpi'];                  // ?float — null when actual cost is unknowable
$evm['method'];               // which basis actually ran, e.g. 'cost'
$evm['coverage'];             // how many measurable activities carried data; coverage_pct is 0–100
$evm['actual_cost_sources'];  // tracked vs inferred vs status-derived AC

// Every method in one pass (mirrors P6's By-Cost / By-Labor-Units pairs)
$all = $analyzer->earnedValueReport('2024-01-15');
$all['by_cost'];
$all['by_labor_units'];
$all['recommended'];
```

Behavior toggles (config `p6-analyzer.evm.*` or the calculator's constructor):
inferring actuals from remaining amounts, honoring P6 `complete_pct_type`,
and status-driven percent overrides. See [docs/analysis.md](docs/analysis.md).

### Querying Schedule Data

```php
$analyzer = new P6AnalyzerManager();
$analyzer->load('/path/to/schedule.xer');

$query = $analyzer->query();
$projects = $query->listProjects();
$activity = $query->getActivity('A1000');
$results = $query->searchActivities('concrete');
```

### Exporting

```php
$xer = $analyzer->exportXer();                  // XER string
$csv = $analyzer->exportCsv('TASK');             // CSV string
$analyzer->exportXerToFile('/path/to/output.xer'); // Write to file
```

## Testing

```bash
./vendor/bin/pest
```

## Documentation

Detailed documentation is available in the [docs/](docs/) directory:

- [Installation](docs/installation.md)
- [Configuration](docs/configuration.md)
- [Parsing XER Files](docs/parsing.md)
- [Querying Schedule Data](docs/querying.md)
- [Analysis & Calculators](docs/analysis.md)
- [Report Generation](docs/reporting.md)
- [Schedule Comparison](docs/comparison.md)
- [Modifying Schedules](docs/mutations.md)
- [Exporting Data](docs/exporting.md)
- [Facade & Manager API](docs/facade-api.md)
- [Upgrading from 1.x to 2.x](docs/upgrading.md)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for what changed in each release.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Please report security issues privately as described in
[SECURITY.md](SECURITY.md).

## License

MIT — see [LICENSE](LICENSE).
