<?php

use TeckFuture\P6Analyzer\DTOs\ProjectData;
use TeckFuture\P6Analyzer\DTOs\RelationshipData;

test('ProjectData creates from raw array', function () {
    $row = ['proj_id' => '42', 'proj_short_name' => 'PROJ1', 'proj_name' => 'My Project'];
    $project = ProjectData::fromModelOrArray($row);

    expect($project->id)->toBe('42')
        ->and($project->proj_short_name)->toBe('PROJ1')
        ->and($project->proj_name)->toBe('My Project');
});

test('ProjectData handles missing fields', function () {
    $project = ProjectData::fromModelOrArray([]);

    expect($project->id)->toBe('')
        ->and($project->proj_short_name)->toBe('')
        ->and($project->proj_name)->toBe('');
});

test('RelationshipData creates from raw array', function () {
    $row = ['task_pred_id' => '99', 'pred_type' => 'PR_FS', 'lag_hr_cnt' => '8'];
    $rel = RelationshipData::fromModelOrArray($row);

    expect($rel->id)->toBe('99')
        ->and($rel->pred_type)->toBe('PR_FS')
        ->and($rel->lag_hr_cnt)->toBe(8.0);
});

test('RelationshipData handles empty lag', function () {
    $row = ['task_pred_id' => '1', 'pred_type' => 'PR_SS', 'lag_hr_cnt' => ''];
    $rel = RelationshipData::fromModelOrArray($row);

    expect($rel->lag_hr_cnt)->toBeNull();
});
