<?php

use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
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

    expect($relaxed['critical_activities_count'])->toBeGreaterThan($strict['critical_activities_count']);
});

test('floatAnalysis() returns non-empty distribution', function () {
    $result = loadedManager()->floatAnalysis();

    expect($result)->toHaveKeys(['total_activities', 'distribution'])
        ->and($result['total_activities'])->toBeGreaterThan(0);
});

test('dcma14Point() applies the data date when scoring', function () {
    $result = loadedManager()->dcma14Point('2024-01-15');

    // The fixture scores 58.38 without a data date; at 2024-01-15 its invalid dates cost another 10 points
    expect($result['overall_score'])->toBe(48.38);
});

test('dcma14Point() assesses the logic, lead, lag and relationship-type points', function () {
    $points = collect(loadedManager()->dcma14Point('2024-01-15')['points'])->keyBy('key');

    // The fixture has 555 relationships: 18 with negative lag, 29 with positive lag, 455 Finish-to-Start
    expect($points['missing_logic']['assessable'])->toBeTrue()
        ->and($points['missing_logic']['count'])->toBe(6)
        ->and($points['missing_logic']['percentage'])->toBe(1.49)
        ->and($points['missing_logic']['passed'])->toBeTrue()
        ->and($points['leads']['count'])->toBe(18)
        ->and($points['leads']['passed'])->toBeFalse()
        ->and($points['lags']['count'])->toBe(29)
        ->and($points['lags']['percentage'])->toBe(5.23)
        ->and($points['lags']['passed'])->toBeFalse()
        ->and($points['relationship_types']['count'])->toBe(455)
        ->and($points['relationship_types']['percentage'])->toBe(81.98)
        ->and($points['relationship_types']['passed'])->toBeFalse();
});

test('scheduleHealth() and dcma14Point() report the same missing logic', function () {
    $manager = loadedManager();
    $logic = collect($manager->dcma14Point()['points'])->firstWhere('key', 'missing_logic');

    expect($manager->scheduleHealth()['missing_logic'])->toBe(6)
        ->and($logic['count'])->toBe(6);
});

test('dcma14Point() on a schedule without relationships assesses against zero links', function () {
    $data = new ParsedXerData(header: '', tables: collect([
        'TASK' => collect([
            ['task_id' => '1', 'task_code' => 'A1', 'status_code' => 'TK_NotStart', 'task_type' => 'TT_Task'],
            ['task_id' => '2', 'task_code' => 'A2', 'status_code' => 'TK_Complete', 'task_type' => 'TT_Task'],
        ]),
    ]));

    $points = collect((new P6AnalyzerManager)->fromData($data)->dcma14Point()['points'])->keyBy('key');

    // The open activity has no links; the completed one is exempt; there are no relationships to type
    expect($points['missing_logic']['assessable'])->toBeTrue()
        ->and($points['missing_logic']['count'])->toBe(1)
        ->and($points['relationship_types']['assessable'])->toBeFalse()
        ->and($points['relationship_types']['reason'])->toBe('No relationships in scope.');
});

test('earnedValue() returns the documented result keys', function () {
    $result = loadedManager()->earnedValue();

    expect($result)->toHaveKeys(['cpi', 'spi', 'earned_value', 'actual_cost', 'budget_at_completion']);
});

test('earnedValue() takes the method as a string', function () {
    $manager = loadedManager();

    expect($manager->earnedValue(null, 'labor_units')['method'])->toBe('labor_units')
        ->and($manager->earnedValue(null, 'duration')['method'])->toBe('duration');
});

test('earnedValue() reads an empty method string as auto', function () {
    // The fixture's cost coverage clears the auto threshold, so auto resolves to cost
    expect(loadedManager()->earnedValue(null, '')['method'])->toBe('cost');
});

test('earnedValue() rejects a method string it does not know', function () {
    loadedManager()->earnedValue('2024-01-15', 'bogus');
})->throws(InvalidArgumentException::class, 'Unknown EVM method "bogus". Valid values: cost, labor_units, duration, auto.');

test('relationshipAnalysis() total_relationships is greater than zero', function () {
    $result = loadedManager()->relationshipAnalysis();

    expect($result['total_relationships'])->toBeGreaterThan(0);
});

test('scheduleVariance() returns zero slippage when compared to self', function () {
    $manager = loadedManager();
    $result = $manager->scheduleVariance($manager->getData());

    expect($result)->toHaveKeys(['total_activities_compared', 'slipping_activities_count', 'average_variance_days'])
        ->and($result['total_activities_compared'])->toBeGreaterThan(0)
        ->and($result['slipping_activities_count'])->toBe(0);
});

test('scheduleVariance() takes the baseline schedule as $baselineData', function () {
    $manager = loadedManager();
    $result = $manager->scheduleVariance(baselineData: $manager->getData());

    expect($result['total_activities_compared'])->toBeGreaterThan(0)
        ->and($result['slipping_activities_count'])->toBe(0);
});

test('scheduleHealth() returns required health keys', function () {
    $result = loadedManager()->scheduleHealth('2024-01-15');

    expect($result)->toHaveKeys(['total_activities', 'missing_resources', 'missing_logic', 'actual_start_after_data_date', 'lags_count', 'leads_count'])
        ->and($result['total_activities'])->toBeGreaterThan(0);
});

test('resourceUtilization() default period is week', function () {
    $result = loadedManager()->resourceUtilization();

    expect($result)->toHaveKeys(['utilization', 'over_allocations', 'total_resources_analyzed']);
});

test('resourceUtilization() custom daily limit is applied', function () {
    $unlimited = loadedManager()->resourceUtilization('week', 999999);
    $strict = loadedManager()->resourceUtilization('week', 0.001);

    // With a near-zero limit every assignment is over-allocated; with a huge limit none is
    expect($unlimited['over_allocations'])->toBeEmpty()
        ->and($strict['over_allocations'])->not->toBeEmpty();
});

test('resourceUtilization() passes weekStartsOn through to the analyzer', function () {
    $monday = loadedManager()->resourceUtilization('week', null, weekStartsOn: 1);
    $saturday = loadedManager()->resourceUtilization('week', null, weekStartsOn: 6);

    expect($monday['utilization'])->not->toBe($saturday['utilization']);
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
    // The fixture schedule runs in 2026, so the window has to start inside it to hold any activities
    $three = loadedManager()->lookahead('2026-06-01', 3);
    $six = loadedManager()->lookahead('2026-06-01', 6);

    expect($three['lookahead_weeks'])->toBe(3)
        ->and($six['lookahead_weeks'])->toBe(6)
        ->and($six['activity_count'])->toBeGreaterThan($three['activity_count']);
});

test('workPackages() returns non-empty array from real data', function () {
    $result = loadedManager()->workPackages();

    // Real XER has WBS nodes — result should be non-empty
    expect($result)->toBeArray()->not->toBeEmpty();
});

test('workPackages() each item has the required keys', function () {
    $result = loadedManager()->workPackages();

    expect($result)->not->toBeEmpty()
        ->each->toHaveKeys(['name', 'activity_count', 'total_duration_hr', 'total_cost', 'percent_complete_by_count']);
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

    try {
        $result = loadedManager()->exportXerToFile($tmpFile);

        expect($result)->toBeTrue()
            ->and(file_get_contents($tmpFile))->toContain('ERMHDR');
    } finally {
        unlink($tmpFile);
    }
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
