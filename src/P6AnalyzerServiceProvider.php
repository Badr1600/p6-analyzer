<?php

namespace TeckFuture\P6Analyzer;

use Illuminate\Support\ServiceProvider;
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
use TeckFuture\P6Analyzer\IO\CsvExporter;
use TeckFuture\P6Analyzer\IO\ScheduleUpdater;
use TeckFuture\P6Analyzer\IO\XerExporter;
use TeckFuture\P6Analyzer\IO\XerParser;

class P6AnalyzerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/p6-analyzer.php', 'p6-analyzer');

        // Register the manager as a singleton
        $this->app->singleton(P6AnalyzerManager::class);
        $this->app->alias(P6AnalyzerManager::class, 'p6-analyzer');

        // Register all analyzers and IO classes as singletons
        $singletons = [
            CriticalPathCalculator::class,
            FloatAnalyzer::class,
            DCMA14PointChecker::class,
            EarnedValueCalculator::class,
            RelationshipAnalyzer::class,
            ScheduleVarianceCalculator::class,
            ScheduleHealthChecker::class,
            ResourceUtilizationAnalyzer::class,
            WbsAnalyzer::class,
            LookaheadGenerator::class,
            WorkPackageSummarizer::class,
            ProgressSummarizer::class,
            SnapshotComparer::class,
            XerParser::class,
            XerExporter::class,
            CsvExporter::class,
            ScheduleUpdater::class,
        ];

        foreach ($singletons as $class) {
            $this->app->singleton($class);
        }

        // ReportGenerator has constructor dependencies
        $this->app->singleton(ReportGenerator::class, function ($app) {
            return new ReportGenerator(
                $app->make(ScheduleHealthChecker::class),
                $app->make(CriticalPathCalculator::class),
                $app->make(FloatAnalyzer::class),
                $app->make(ProgressSummarizer::class),
                $app->make(WorkPackageSummarizer::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/p6-analyzer.php' => config_path('p6-analyzer.php'),
            ], 'p6-analyzer-config');
        }
    }
}
