# Upgrading from In-App Code

If you're migrating from `App\Support\P6Analyzer` to this package, follow these steps.

## 1. Install the Package

```bash
composer require teckfuture/laravel-p6-analyzer
```

## 2. Update Namespace Imports

Replace all `App\Support\P6Analyzer\...` imports with `TeckFuture\P6Analyzer\...`:

| Old Namespace | New Namespace |
|---|---|
| `App\Support\P6Analyzer\CriticalPathCalculator` | `TeckFuture\P6Analyzer\Analyzers\CriticalPathCalculator` |
| `App\Support\P6Analyzer\FloatAnalyzer` | `TeckFuture\P6Analyzer\Analyzers\FloatAnalyzer` |
| `App\Support\P6Analyzer\DCMA14PointChecker` | `TeckFuture\P6Analyzer\Analyzers\DCMA14PointChecker` |
| `App\Support\P6Analyzer\EarnedValueCalculator` | `TeckFuture\P6Analyzer\Analyzers\EarnedValueCalculator` |
| `App\Support\P6Analyzer\RelationshipAnalyzer` | `TeckFuture\P6Analyzer\Analyzers\RelationshipAnalyzer` |
| `App\Support\P6Analyzer\ScheduleVarianceCalculator` | `TeckFuture\P6Analyzer\Analyzers\ScheduleVarianceCalculator` |
| `App\Support\P6Analyzer\ScheduleHealthChecker` | `TeckFuture\P6Analyzer\Analyzers\ScheduleHealthChecker` |
| `App\Support\P6Analyzer\DTOs\ActivityData` | `TeckFuture\P6Analyzer\DTOs\ActivityData` |
| `App\Support\P6Analyzer\DTOs\RelationshipData` | `TeckFuture\P6Analyzer\DTOs\RelationshipData` |
| `App\Support\P6Analyzer\DTOs\ProjectData` | `TeckFuture\P6Analyzer\DTOs\ProjectData` |
| `App\Support\P6Analyzer\DTOs\ParsedXerData` | `TeckFuture\P6Analyzer\DTOs\ParsedXerData` |
| `App\Support\P6Analyzer\XerParser` | `TeckFuture\P6Analyzer\IO\XerParser` |
| `App\Support\P6Analyzer\XerExporter` | `TeckFuture\P6Analyzer\IO\XerExporter` |

## 3. Remove Old Code

Delete the `app/Support/P6Analyzer/` directory and `tests/Unit/P6Analyzer/` directory.

## 4. Breaking Changes

- DTOs no longer extend `Spatie\LaravelData\Data`. They are plain `final` PHP classes with `readonly` properties.
- `XerParser` now throws `XerParseException` instead of generic `Exception`.
- Status codes are now automatically normalized (e.g., `TK_Complete` → `Completed`).
- Analyzers are sub-namespaced: `Analyzers\`, `IO\`, `DTOs\`.
