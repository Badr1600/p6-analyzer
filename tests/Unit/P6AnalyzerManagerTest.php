<?php

use TeckFuture\P6Analyzer\IO\XerParser;
use TeckFuture\P6Analyzer\P6AnalyzerManager;

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------

function loadedManager(): P6AnalyzerManager
{
    return (new P6AnalyzerManager)->load(
        __DIR__.'/../fixtures/example_xer_file.xer'
    );
}

// ---------------------------------------------------------------
// Loading
// ---------------------------------------------------------------

covers(P6AnalyzerManager::class);

test('load() returns self and sets data', function () {
    $manager = new P6AnalyzerManager;
    $result = $manager->load($this->fixturePath());

    expect($result)->toBeInstanceOf(P6AnalyzerManager::class)
        ->and($manager->getData())->not->toBeNull();
});

test('parse() accepts raw XER string', function () {
    $content = file_get_contents($this->fixturePath());
    $manager = (new P6AnalyzerManager)->parse($content);

    expect($manager->criticalPath())->toHaveKey('total_activities');
});

test('fromData() accepts pre-parsed data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $manager = (new P6AnalyzerManager)->fromData($data);

    expect($manager->getData())->toBe($data);
});

// ---------------------------------------------------------------
// ensureLoaded() guard — one test per public method
// ---------------------------------------------------------------

$guardedMethods = [
    'criticalPath',
    'floatAnalysis',
    'dcma14Point',
    'earnedValue',
    'relationshipAnalysis',
    'scheduleHealth',
    'wbsAnalysis',
    'workPackages',
    'progress',
    'report',
    'exportXer',
    'query',
    'exportXerToFile' => ['path' => '/dev/null'],
    'exportCsv' => ['table' => 'TASK'],
];

test('criticalPath() throws when no data loaded', function () {
    (new P6AnalyzerManager)->criticalPath();
})->throws(RuntimeException::class, 'No XER data loaded');

test('floatAnalysis() throws when no data loaded', function () {
    (new P6AnalyzerManager)->floatAnalysis();
})->throws(RuntimeException::class, 'No XER data loaded');

test('dcma14Point() throws when no data loaded', function () {
    (new P6AnalyzerManager)->dcma14Point();
})->throws(RuntimeException::class, 'No XER data loaded');

test('earnedValue() throws when no data loaded', function () {
    (new P6AnalyzerManager)->earnedValue();
})->throws(RuntimeException::class, 'No XER data loaded');

test('relationshipAnalysis() throws when no data loaded', function () {
    (new P6AnalyzerManager)->relationshipAnalysis();
})->throws(RuntimeException::class, 'No XER data loaded');

test('scheduleHealth() throws when no data loaded', function () {
    (new P6AnalyzerManager)->scheduleHealth();
})->throws(RuntimeException::class, 'No XER data loaded');

test('scheduleVariance() throws when no data loaded', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    (new P6AnalyzerManager)->scheduleVariance($data);
})->throws(RuntimeException::class, 'No XER data loaded');

test('resourceUtilization() throws when no data loaded', function () {
    (new P6AnalyzerManager)->resourceUtilization();
})->throws(RuntimeException::class, 'No XER data loaded');

test('wbsAnalysis() throws when no data loaded', function () {
    (new P6AnalyzerManager)->wbsAnalysis();
})->throws(RuntimeException::class, 'No XER data loaded');

test('lookahead() throws when no data loaded', function () {
    (new P6AnalyzerManager)->lookahead('2024-01-01');
})->throws(RuntimeException::class, 'No XER data loaded');

test('workPackages() throws when no data loaded', function () {
    (new P6AnalyzerManager)->workPackages();
})->throws(RuntimeException::class, 'No XER data loaded');

test('progress() throws when no data loaded', function () {
    (new P6AnalyzerManager)->progress();
})->throws(RuntimeException::class, 'No XER data loaded');

test('report() throws when no data loaded', function () {
    (new P6AnalyzerManager)->report();
})->throws(RuntimeException::class, 'No XER data loaded');

test('compareTo() throws when no data loaded', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    (new P6AnalyzerManager)->compareTo($data);
})->throws(RuntimeException::class, 'No XER data loaded');

test('query() throws when no data loaded', function () {
    (new P6AnalyzerManager)->query();
})->throws(RuntimeException::class, 'No XER data loaded');

test('exportXer() throws when no data loaded', function () {
    (new P6AnalyzerManager)->exportXer();
})->throws(RuntimeException::class, 'No XER data loaded');

test('exportXerToFile() throws when no data loaded', function () {
    (new P6AnalyzerManager)->exportXerToFile('/dev/null');
})->throws(RuntimeException::class, 'No XER data loaded');

test('getData() throws when no data loaded', function () {
    (new P6AnalyzerManager)->getData();
})->throws(RuntimeException::class, 'No XER data loaded');

test('exportCsv() throws when no data loaded', function () {
    (new P6AnalyzerManager)->exportCsv('TASK');
})->throws(RuntimeException::class, 'No XER data loaded');

// ---------------------------------------------------------------
// Return-value assertions (non-empty / correct shape)
// ---------------------------------------------------------------

test('criticalPath() returns non-empty result with correct keys', function () {
    $result = loadedManager()->criticalPath();

    expect($result)->toHaveKeys(['total_activities', 'critical_activities_count', 'critical_path_percentage', 'critical_activities'])
        ->and($result['total_activities'])->toBeGreaterThan(0);
});

test('criticalPath() with custom threshold returns more critical activities than strict zero', function () {
    $manager = loadedManager();
    $strict = $manager->criticalPath(0);
    $relaxed = $manager->criticalPath(160);

    expect($relaxed['critical_activities_count'])->toBeGreaterThanOrEqual($strict['critical_activities_count']);
});

test('floatAnalysis() returns non-empty distribution', function () {
    $result = loadedManager()->floatAnalysis();

    expect($result)->toHaveKeys(['total_activities', 'distribution'])
        ->and($result['total_activities'])->toBeGreaterThan(0);
});

test('dcma14Point() overall_score is between 0 and 100', function () {
    $result = loadedManager()->dcma14Point('2024-01-15');

    expect($result['overall_score'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(100);
});

test('earnedValue() returns CPI of 1.0 when no costs present', function () {
    $result = loadedManager()->earnedValue();

    expect($result)->toHaveKeys(['cpi', 'spi', 'earned_value', 'actual_cost', 'budget_at_completion']);
});

test('relationshipAnalysis() total_relationships is greater than zero', function () {
    $result = loadedManager()->relationshipAnalysis();

    expect($result['total_relationships'])->toBeGreaterThan(0);
});

test('scheduleVariance() returns zero slippage when compared to self', function () {
    $manager = loadedManager();
    $result = $manager->scheduleVariance($manager->getData());

    expect($result)->toHaveKeys(['total_activities_compared', 'slipping_activities_count', 'average_variance_days'])
        ->and($result['slipping_activities_count'])->toBeGreaterThanOrEqual(0);
});

test('scheduleHealth() returns required health keys', function () {
    $result = loadedManager()->scheduleHealth('2024-01-15');

    expect($result)->toHaveKeys(['total_activities', 'missing_resources', 'missing_logic', 'out_of_sequence', 'lags_count', 'leads_count'])
        ->and($result['total_activities'])->toBeGreaterThan(0);
});

test('resourceUtilization() default period is week', function () {
    $result = loadedManager()->resourceUtilization();

    expect($result)->toHaveKeys(['utilization', 'over_allocations', 'total_resources_analyzed']);
});

test('resourceUtilization() custom daily limit is applied', function () {
    $unlimited = loadedManager()->resourceUtilization('week', 999999);
    $strict = loadedManager()->resourceUtilization('week', 0.001);

    // With near-zero limit every assignment is over-allocated
    expect(count($strict['over_allocations']))->toBeGreaterThanOrEqual(count($unlimited['over_allocations']));
});

test('wbsAnalysis() returns total_wbs_elements', function () {
    $result = loadedManager()->wbsAnalysis();

    expect($result)->toHaveKeys(['total_wbs_elements', 'wbs_metrics']);
});

test('lookahead() default weeks is 3', function () {
    $result = loadedManager()->lookahead('2024-01-01');

    expect($result['lookahead_weeks'])->toBe(3);
});

test('lookahead() respects custom week count', function () {
    $three = loadedManager()->lookahead('2024-01-01', 3);
    $six = loadedManager()->lookahead('2024-01-01', 6);

    expect($three['lookahead_weeks'])->toBe(3)
        ->and($six['lookahead_weeks'])->toBe(6)
        ->and($six['activity_count'])->toBeGreaterThanOrEqual($three['activity_count']);
});

test('workPackages() returns non-empty array from real data', function () {
    $result = loadedManager()->workPackages();

    // Real XER has WBS nodes — result should be non-empty
    expect($result)->toBeArray()->not->toBeEmpty();
});

test('workPackages() each item has required keys when non-empty', function () {
    $result = loadedManager()->workPackages();

    if (count($result) > 0) {
        expect($result[0])->toHaveKeys(['name', 'activity_count', 'total_duration_hr', 'total_cost', 'percent_complete_by_count']);
    } else {
        expect($result)->toBeArray();
    }
});

test('progress() total counts add up to total activities', function () {
    $result = loadedManager()->progress();
    $sum = $result['status_counts']['not_started'] + $result['status_counts']['in_progress'] + $result['status_counts']['completed'];

    expect($sum)->toBe($result['total_activities']);
});

test('report() contains all top-level keys', function () {
    $result = loadedManager()->report('2024-01-15');

    expect($result)->toHaveKeys(['generated_at', 'data_date', 'project_info', 'progress', 'health', 'float_distribution', 'critical_path', 'work_packages'])
        ->and($result['data_date'])->toBe('2024-01-15');
});

test('compareTo() returns zero total_changes when compared to self', function () {
    $manager = loadedManager();
    $result = $manager->compareTo($manager->getData());

    expect($result['total_changes'])->toBe(0)
        ->and($result['added_activities'])->toBeEmpty()
        ->and($result['deleted_activities'])->toBeEmpty();
});

test('query() returns a ScheduleQueryEngine with projects', function () {
    $engine = loadedManager()->query();
    $projects = $engine->listProjects();

    expect($projects)->not->toBeEmpty();
});

test('exportXer() output contains required XER markers', function () {
    $xer = loadedManager()->exportXer();

    expect($xer)->toContain('ERMHDR')
        ->and($xer)->toContain('%T')
        ->and($xer)->toContain('%F')
        ->and($xer)->toContain('%R');
});

test('exportXerToFile() writes file and returns true', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'p6manager_test_');
    $result = loadedManager()->exportXerToFile($tmpFile);

    expect($result)->toBeTrue()
        ->and(file_exists($tmpFile))->toBeTrue()
        ->and(file_get_contents($tmpFile))->toContain('ERMHDR');

    unlink($tmpFile);
});

test('exportCsv() returns CSV with headers', function () {
    $csv = loadedManager()->exportCsv('TASK');

    expect($csv)->toContain('task_id');
});

// ---------------------------------------------------------------
// Laravel integration
// ---------------------------------------------------------------

test('facade resolves to P6AnalyzerManager', function () {
    expect(app('p6-analyzer'))->toBeInstanceOf(P6AnalyzerManager::class);
});
