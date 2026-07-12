<?php

use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\DTOs\ProjectData;
use TeckFuture\P6Analyzer\DTOs\RelationshipData;

test('getTable returns empty collection for missing tables', function () {
    $data = new ParsedXerData(header: '', tables: collect());

    expect($data->getTable('NONEXISTENT'))->toBeEmpty();
});

test('getTable returns rows for existing table', function () {
    $data = new ParsedXerData(header: 'ERMHDR', tables: collect([
        'TASK' => collect([
            ['task_id' => '1', 'task_code' => 'A1', 'task_name' => 'Test'],
        ]),
    ]));

    expect($data->getTable('TASK'))->toHaveCount(1);
});

test('getActivities returns ActivityData instances', function () {
    $data = new ParsedXerData(header: '', tables: collect([
        'TASK' => collect([
            ['task_id' => '1', 'task_code' => 'A1', 'task_name' => 'Test', 'status_code' => 'TK_Active'],
        ]),
    ]));

    $activities = $data->getActivities();

    expect($activities)->toHaveCount(1)
        ->and($activities->first())->toBeInstanceOf(ActivityData::class)
        ->and($activities->first()->status_code)->toBe('In Progress');
});

test('getProjects returns ProjectData instances', function () {
    $data = new ParsedXerData(header: '', tables: collect([
        'PROJECT' => collect([
            ['proj_id' => '1', 'proj_short_name' => 'P1', 'proj_name' => 'Project One'],
        ]),
    ]));

    $projects = $data->getProjects();

    expect($projects)->toHaveCount(1)
        ->and($projects->first())->toBeInstanceOf(ProjectData::class);
});

test('getRelationships returns RelationshipData instances', function () {
    $data = new ParsedXerData(header: '', tables: collect([
        'TASKPRED' => collect([
            ['task_pred_id' => '1', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '0'],
        ]),
    ]));

    $rels = $data->getRelationships();

    expect($rels)->toHaveCount(1)
        ->and($rels->first())->toBeInstanceOf(RelationshipData::class);
});
