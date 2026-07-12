<?php

use TeckFuture\P6Analyzer\P6AnalyzerManager;

test('load and run all analyses via Manager', function () {
    $manager = new P6AnalyzerManager;
    $manager->load($this->fixturePath());

    expect($manager->criticalPath())->toHaveKey('total_activities')
        ->and($manager->floatAnalysis())->toHaveKey('distribution')
        ->and($manager->dcma14Point())->toHaveKey('overall_score')
        ->and($manager->earnedValue())->toHaveKey('cpi')
        ->and($manager->relationshipAnalysis())->toHaveKey('total_relationships')
        ->and($manager->scheduleHealth())->toHaveKey('missing_logic')
        ->and($manager->wbsAnalysis())->toHaveKey('total_wbs_elements')
        ->and($manager->workPackages())->toBeArray()
        ->and($manager->progress())->toHaveKey('total_activities')
        ->and($manager->report())->toHaveKey('generated_at');
});

test('parse from string works', function () {
    $content = file_get_contents($this->fixturePath());
    $manager = new P6AnalyzerManager;
    $manager->parse($content);

    expect($manager->criticalPath())->toHaveKey('total_activities');
});

test('throws when no data loaded', function () {
    $manager = new P6AnalyzerManager;
    $manager->criticalPath();
})->throws(RuntimeException::class, 'No XER data loaded');

test('facade resolves correctly', function () {
    $resolved = app('p6-analyzer');

    expect($resolved)->toBeInstanceOf(P6AnalyzerManager::class);
});

test('exportXer produces valid XER output', function () {
    $manager = new P6AnalyzerManager;
    $manager->load($this->fixturePath());

    $xer = $manager->exportXer();

    expect($xer)->toContain('ERMHDR')
        ->and($xer)->toContain('%T')
        ->and($xer)->toContain('%F')
        ->and($xer)->toContain('%R');
});

test('exportCsv produces CSV output', function () {
    $manager = new P6AnalyzerManager;
    $manager->load($this->fixturePath());

    $csv = $manager->exportCsv('TASK');

    expect($csv)->toContain('task_id');
});

test('compareTo works', function () {
    $manager = new P6AnalyzerManager;
    $manager->load($this->fixturePath());

    $result = $manager->compareTo($manager->getData());

    expect($result['total_changes'])->toBe(0);
});

test('query engine is accessible', function () {
    $manager = new P6AnalyzerManager;
    $manager->load($this->fixturePath());

    $engine = $manager->query();
    $projects = $engine->listProjects();

    expect($projects)->not->toBeEmpty();
});
