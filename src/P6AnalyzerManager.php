<?php

namespace TeckFuture\P6Analyzer;

use TeckFuture\P6Analyzer\Analyzers\CriticalPathCalculator;
use TeckFuture\P6Analyzer\Analyzers\DCMA14PointChecker;
use TeckFuture\P6Analyzer\Analyzers\EarnedValueCalculator;
use TeckFuture\P6Analyzer\Analyzers\FloatAnalyzer;
use TeckFuture\P6Analyzer\Analyzers\LookaheadGenerator;
use TeckFuture\P6Analyzer\Analyzers\ProgressSummarizer;
use TeckFuture\P6Analyzer\Analyzers\RelationshipAnalyzer;
use TeckFuture\P6Analyzer\Analyzers\ReportGenerator;
use TeckFuture\P6Analyzer\Analyzers\ResourceUtilizationAnalyzer;
use TeckFuture\P6Analyzer\Analyzers\ScheduleHealthChecker;
use TeckFuture\P6Analyzer\Analyzers\ScheduleVarianceCalculator;
use TeckFuture\P6Analyzer\Analyzers\SnapshotComparer;
use TeckFuture\P6Analyzer\Analyzers\WbsAnalyzer;
use TeckFuture\P6Analyzer\Analyzers\WorkPackageSummarizer;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\IO\CsvExporter;
use TeckFuture\P6Analyzer\IO\ScheduleQueryEngine;
use TeckFuture\P6Analyzer\IO\ScheduleUpdater;
use TeckFuture\P6Analyzer\IO\XerExporter;
use TeckFuture\P6Analyzer\IO\XerParser;

/**
 * The primary API entry point for the P6 Analyzer library.
 *
 * Provides a fluent interface for loading, analyzing, querying,
 * and exporting P6 XER schedule data.
 *
 * @example
 * ```php
 * $analyzer = new P6AnalyzerManager();
 * $results = $analyzer->load('/path/to/file.xer')->criticalPath();
 * $report = $analyzer->report('2024-01-01');
 * ```
 */
class P6AnalyzerManager
{
    protected ?ParsedXerData $data = null;

    /**
     * Load and parse an XER file.
     */
    public function load(string $filePath): self
    {
        $this->data = (new XerParser)->parseFile($filePath);

        return $this;
    }

    /**
     * Parse an XER string directly.
     */
    public function parse(string $content): self
    {
        $this->data = (new XerParser)->parse($content);

        return $this;
    }

    /**
     * Use an already-parsed ParsedXerData instance.
     */
    public function fromData(ParsedXerData $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Get the underlying ParsedXerData.
     */
    public function getData(): ParsedXerData
    {
        $this->ensureLoaded();

        return $this->data;
    }

    // ---------------------------------------------------------------
    // Analyzers that accept Collection<ActivityData>
    // ---------------------------------------------------------------

    /**
     * Run critical path analysis.
     *
     * @return array{total_activities: int, critical_activities_count: int, critical_path_percentage: float, critical_activities: array}
     */
    public function criticalPath(?float $threshold = null): array
    {
        $this->ensureLoaded();

        return (new CriticalPathCalculator)->calculate($this->data->getActivities(), $threshold);
    }

    /**
     * Run float distribution analysis.
     *
     * @return array{total_activities: int, distribution: array}
     */
    public function floatAnalysis(): array
    {
        $this->ensureLoaded();

        return (new FloatAnalyzer)->calculate($this->data->getActivities());
    }

    /**
     * Run DCMA 14-point schedule quality check.
     *
     * @return array{total_activities: int, incomplete_activities: int, negative_float_count: int, overall_score: float}
     */
    public function dcma14Point(?string $dataDate = null): array
    {
        $this->ensureLoaded();

        return (new DCMA14PointChecker)->calculate($this->data->getActivities(), $dataDate);
    }

    /**
     * Run earned value analysis.
     *
     * @return array{planned_value: float, earned_value: float, actual_cost: float, cpi: float, spi: float}
     */
    public function earnedValue(?string $dataDate = null): array
    {
        $this->ensureLoaded();

        return (new EarnedValueCalculator)->calculate($this->data->getActivities(), $dataDate);
    }

    /**
     * Run relationship analysis.
     *
     * @return array{total_relationships: int, type_distribution: array, lag_distribution: array}
     */
    public function relationshipAnalysis(): array
    {
        $this->ensureLoaded();

        return (new RelationshipAnalyzer)->calculate($this->data->getRelationships());
    }

    /**
     * Run schedule variance (slippage) analysis.
     *
     * @param  ParsedXerData  $masterData  Baseline schedule to compare against.
     * @return array{total_activities_compared: int, slipping_activities_count: int, average_variance_days: float}
     */
    public function scheduleVariance(ParsedXerData $masterData): array
    {
        $this->ensureLoaded();

        return (new ScheduleVarianceCalculator)->calculate(
            $this->data->getActivities(),
            $masterData->getActivities(),
        );
    }

    // ---------------------------------------------------------------
    // Analyzers that accept ParsedXerData
    // ---------------------------------------------------------------

    /**
     * Run schedule health check.
     *
     * @return array{total_activities: int, missing_resources: int, missing_logic: int, out_of_sequence: int}
     */
    public function scheduleHealth(?string $dataDate = null): array
    {
        $this->ensureLoaded();

        return (new ScheduleHealthChecker)->check($this->data, $dataDate);
    }

    /**
     * Run resource utilization analysis.
     *
     * @return array{utilization: array, over_allocations: array, total_resources_analyzed: int}
     */
    public function resourceUtilization(string $period = 'week', ?float $dailyLimit = null): array
    {
        $this->ensureLoaded();

        return (new ResourceUtilizationAnalyzer)->calculate($this->data, $period, $dailyLimit);
    }

    /**
     * Run WBS analysis.
     *
     * @return array{total_wbs_elements: int, wbs_metrics: array}
     */
    public function wbsAnalysis(): array
    {
        $this->ensureLoaded();

        return (new WbsAnalyzer)->calculate($this->data);
    }

    /**
     * Generate a lookahead schedule.
     *
     * @return array{data_date: string, lookahead_weeks: int, activity_count: int, activities: array}
     */
    public function lookahead(string $dataDate, int $weeks = 3): array
    {
        $this->ensureLoaded();

        return (new LookaheadGenerator)->generate($this->data, $dataDate, $weeks);
    }

    /**
     * Summarize work packages.
     *
     * @return array<int, array{name: string, activity_count: int, percent_complete_by_count: float}>
     */
    public function workPackages(): array
    {
        $this->ensureLoaded();

        return (new WorkPackageSummarizer)->summarize($this->data);
    }

    /**
     * Summarize overall progress.
     *
     * @return array{total_activities: int, status_counts: array, overall_percent_complete_by_duration: float}
     */
    public function progress(): array
    {
        $this->ensureLoaded();

        return (new ProgressSummarizer)->summarize($this->data);
    }

    /**
     * Generate a comprehensive report.
     *
     * @return array{generated_at: string, project_info: array, progress: array, health: array, float_distribution: array, critical_path: array, work_packages: array}
     */
    public function report(?string $dataDate = null): array
    {
        $this->ensureLoaded();

        $generator = new ReportGenerator(
            new ScheduleHealthChecker,
            new CriticalPathCalculator,
            new FloatAnalyzer,
            new ProgressSummarizer,
            new WorkPackageSummarizer,
        );

        return $generator->generate($this->data, $dataDate);
    }

    /**
     * Compare the current schedule against a baseline.
     *
     * @return array{added_activities: array, deleted_activities: array, modified_activities: array, total_changes: int}
     */
    public function compareTo(ParsedXerData $baselineData): array
    {
        $this->ensureLoaded();

        return (new SnapshotComparer)->compare($this->data, $baselineData);
    }

    // ---------------------------------------------------------------
    // Query & IO
    // ---------------------------------------------------------------

    /**
     * Get a query engine for searching/filtering the schedule data.
     */
    public function query(): ScheduleQueryEngine
    {
        $this->ensureLoaded();

        return new ScheduleQueryEngine($this->data);
    }

    /**
     * Get a schedule updater for in-memory modifications.
     */
    public function updater(): ScheduleUpdater
    {
        return new ScheduleUpdater;
    }

    /**
     * Export the current data back to XER format string.
     */
    public function exportXer(): string
    {
        $this->ensureLoaded();

        return (new XerExporter)->export($this->data);
    }

    /**
     * Export the current data to an XER file.
     */
    public function exportXerToFile(string $filePath): bool
    {
        $this->ensureLoaded();

        return (new XerExporter)->exportToFile($this->data, $filePath);
    }

    /**
     * Export a table to CSV format.
     */
    public function exportCsv(string $tableName): string
    {
        $this->ensureLoaded();

        return (new CsvExporter)->export($this->data->getTable($tableName));
    }

    /**
     * Ensure data has been loaded before running analyses.
     *
     * @throws \RuntimeException
     */
    private function ensureLoaded(): void
    {
        if ($this->data === null) {
            throw new \RuntimeException('No XER data loaded. Call load(), parse(), or fromData() first.');
        }
    }
}
