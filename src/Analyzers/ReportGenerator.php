<?php

namespace TeckFuture\P6Analyzer\Analyzers;

use Carbon\Carbon;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;

/**
 * Generates a comprehensive schedule analysis report.
 */
class ReportGenerator
{
    public function __construct(
        protected ScheduleHealthChecker $healthChecker,
        protected CriticalPathCalculator $cpmCalculator,
        protected FloatAnalyzer $floatAnalyzer,
        protected ProgressSummarizer $progressSummarizer,
        protected WorkPackageSummarizer $wpSummarizer,
    ) {}

    /**
     * Generate a full report covering all analysis dimensions.
     *
     * @return array{generated_at: string, data_date: string|null, project_info: array, progress: array, health: array, float_distribution: array, critical_path: array, work_packages: array}
     */
    public function generate(ParsedXerData $data, ?string $dataDate = null): array
    {
        $activities = $data->getActivities();

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'data_date' => $dataDate,
            'project_info' => $this->getProjectSummary($data),
            'progress' => $this->progressSummarizer->summarize($data),
            'health' => $this->healthChecker->check($data, $dataDate),
            'float_distribution' => $this->floatAnalyzer->calculate($activities),
            'critical_path' => $this->cpmCalculator->calculate($activities),
            'work_packages' => $this->wpSummarizer->summarize($data),
        ];
    }

    /**
     * @return array{id?: string, short_name?: string, name?: string}
     */
    private function getProjectSummary(ParsedXerData $data): array
    {
        $projects = $data->getProjects();
        if ($projects->isEmpty()) {
            return [];
        }

        $project = $projects->first();

        return [
            'id' => $project->id,
            'short_name' => $project->proj_short_name,
            'name' => $project->proj_name,
        ];
    }
}
