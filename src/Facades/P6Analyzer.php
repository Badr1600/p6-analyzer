<?php

namespace TeckFuture\P6Analyzer\Facades;

use Illuminate\Support\Facades\Facade;
use TeckFuture\P6Analyzer\P6AnalyzerManager;

/**
 * @method static P6AnalyzerManager load(string $filePath)
 * @method static P6AnalyzerManager parse(string $content)
 * @method static P6AnalyzerManager fromData(\TeckFuture\P6Analyzer\DTOs\ParsedXerData $data)
 * @method static array criticalPath(?float $threshold = null)
 * @method static array floatAnalysis()
 * @method static array dcma14Point(?string $dataDate = null)
 * @method static array earnedValue(?string $dataDate = null)
 * @method static array relationshipAnalysis()
 * @method static array scheduleHealth(?string $dataDate = null)
 * @method static array resourceUtilization(string $period = 'week', ?float $dailyLimit = null, ?int $weekStartsOn = null, array $roleLabels = [])
 * @method static array wbsAnalysis()
 * @method static array lookahead(string $dataDate, int $weeks = 3)
 * @method static array workPackages()
 * @method static array progress()
 * @method static array report(?string $dataDate = null)
 * @method static array compareTo(\TeckFuture\P6Analyzer\DTOs\ParsedXerData $baselineData)
 * @method static string exportXer()
 * @method static string exportCsv(string $tableName)
 *
 * @see P6AnalyzerManager
 */
class P6Analyzer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return P6AnalyzerManager::class;
    }
}
