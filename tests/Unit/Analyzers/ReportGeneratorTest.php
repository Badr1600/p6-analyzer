<?php

use TeckFuture\P6Analyzer\Analyzers\CriticalPathCalculator;
use TeckFuture\P6Analyzer\Analyzers\FloatAnalyzer;
use TeckFuture\P6Analyzer\Analyzers\ProgressSummarizer;
use TeckFuture\P6Analyzer\Analyzers\ReportGenerator;
use TeckFuture\P6Analyzer\Analyzers\ScheduleHealthChecker;
use TeckFuture\P6Analyzer\Analyzers\WorkPackageSummarizer;
use TeckFuture\P6Analyzer\IO\XerParser;

test('ReportGenerator generates a full report', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());

    $generator = new ReportGenerator(
        new ScheduleHealthChecker,
        new CriticalPathCalculator,
        new FloatAnalyzer,
        new ProgressSummarizer,
        new WorkPackageSummarizer,
    );

    $report = $generator->generate($data, '2024-01-15');

    expect($report)->toHaveKeys(['generated_at', 'data_date', 'project_info', 'progress', 'health', 'float_distribution', 'critical_path', 'work_packages'])
        ->and($report['data_date'])->toBe('2024-01-15');
});
