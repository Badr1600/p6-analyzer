<?php

namespace TeckFuture\P6Analyzer\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use TeckFuture\P6Analyzer\Facades\P6Analyzer;
use TeckFuture\P6Analyzer\P6AnalyzerServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            P6AnalyzerServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'P6Analyzer' => P6Analyzer::class,
        ];
    }

    protected function fixturePath(string $filename = 'example_xer_file.xer'): string
    {
        return __DIR__.'/fixtures/'.$filename;
    }
}
