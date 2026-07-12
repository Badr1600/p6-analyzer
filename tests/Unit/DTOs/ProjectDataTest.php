<?php

use TeckFuture\P6Analyzer\DTOs\ProjectData;

covers(ProjectData::class);

// ---------------------------------------------------------------
// Array path — no raw_data key (falls back to whole array as rawData)
// ---------------------------------------------------------------

test('array without raw_data key uses whole array as rawData', function () {
    $row = ['proj_id' => '42', 'proj_short_name' => 'PROJ1', 'proj_name' => 'My Project'];
    $project = ProjectData::fromModelOrArray($row);

    expect($project->id)->toBe('42')
        ->and($project->proj_short_name)->toBe('PROJ1')
        ->and($project->proj_name)->toBe('My Project')
        ->and($project->raw_data)->toBe($row);
});

// ---------------------------------------------------------------
// Array path — WITH raw_data key (uses nested raw_data)
// ---------------------------------------------------------------

test('array with raw_data key uses nested raw_data', function () {
    $innerData = ['proj_id' => '99', 'proj_short_name' => 'INNER', 'proj_name' => 'Inner Project'];
    $row = [
        'id' => '99',
        'raw_data' => $innerData,
    ];
    $project = ProjectData::fromModelOrArray($row);

    // raw_data key is present, so raw_data = $innerData
    expect($project->raw_data)->toBe($innerData)
        ->and($project->proj_short_name)->toBe('INNER')
        ->and($project->proj_name)->toBe('Inner Project');
});

// ---------------------------------------------------------------
// Array path — id resolution: array 'id' key vs proj_id fallback
// ---------------------------------------------------------------

test('uses array id key when present', function () {
    $project = ProjectData::fromModelOrArray(['id' => 'A1', 'proj_short_name' => 'P', 'proj_name' => 'P']);

    expect($project->id)->toBe('A1');
});

test('falls back to proj_id when array id key is absent', function () {
    $project = ProjectData::fromModelOrArray(['proj_id' => 'P99', 'proj_short_name' => 'P', 'proj_name' => 'P']);

    expect($project->id)->toBe('P99');
});

test('id is empty string when neither id nor proj_id present', function () {
    $project = ProjectData::fromModelOrArray([]);

    expect($project->id)->toBe('');
});

// ---------------------------------------------------------------
// Array path — field values are cast to string
// ---------------------------------------------------------------

test('numeric proj_short_name is cast to string', function () {
    $project = ProjectData::fromModelOrArray(['proj_short_name' => 123, 'proj_name' => 456]);

    expect($project->proj_short_name)->toBe('123')
        ->and($project->proj_short_name)->toBeString()
        ->and($project->proj_name)->toBe('456')
        ->and($project->proj_name)->toBeString();
});

// ---------------------------------------------------------------
// Object path — raw_data is an array (happy path)
// ---------------------------------------------------------------

test('object with array raw_data uses it directly', function () {
    $obj = new stdClass;
    $obj->id = 'OBJ1';
    $obj->raw_data = ['proj_short_name' => 'ObjProj', 'proj_name' => 'Object Project'];

    $project = ProjectData::fromModelOrArray($obj);

    expect($project->id)->toBe('OBJ1')
        ->and($project->proj_short_name)->toBe('ObjProj')
        ->and($project->proj_name)->toBe('Object Project')
        ->and($project->raw_data)->toBe($obj->raw_data);
});

// ---------------------------------------------------------------
// Object path — raw_data is null (uses empty array cast)
// ---------------------------------------------------------------

test('object with null raw_data uses empty array', function () {
    $obj = new stdClass;
    $obj->id = 'OBJ2';
    $obj->raw_data = null;

    $project = ProjectData::fromModelOrArray($obj);

    expect($project->id)->toBe('OBJ2')
        ->and($project->raw_data)->toBe([])
        ->and($project->proj_short_name)->toBe('');
});

// ---------------------------------------------------------------
// Object path — raw_data property not set (triggers ??)
// ---------------------------------------------------------------

test('object without raw_data property uses empty array', function () {
    $obj = new stdClass;
    $obj->id = 'OBJ3';
    // no raw_data property

    $project = ProjectData::fromModelOrArray($obj);

    expect($project->id)->toBe('OBJ3')
        ->and($project->raw_data)->toBeArray()
        ->and($project->proj_short_name)->toBe('');
});

// ---------------------------------------------------------------
// Object path — raw_data is a scalar (triggers array cast)
// ---------------------------------------------------------------

test('object with scalar raw_data is cast to array', function () {
    $obj = new stdClass;
    $obj->id = 'OBJ4';
    $obj->raw_data = 'some-string'; // not an array

    $project = ProjectData::fromModelOrArray($obj);

    // scalar cast: (array) 'some-string' = [0 => 'some-string']
    expect($project->raw_data)->toBeArray()
        ->and($project->id)->toBe('OBJ4');
});

// ---------------------------------------------------------------
// Object path — id is cast from non-string
// ---------------------------------------------------------------

test('object id is cast to string', function () {
    $obj = new stdClass;
    $obj->id = 42; // integer
    $obj->raw_data = ['proj_short_name' => 'P', 'proj_name' => 'P'];

    $project = ProjectData::fromModelOrArray($obj);

    expect($project->id)->toBe('42')
        ->and($project->id)->toBeString();
});

// ---------------------------------------------------------------
// Empty string default values (kills EmptyStringToNotEmpty)
// ---------------------------------------------------------------

test('missing proj_name defaults to empty string not a non-empty fallback', function () {
    // Kills EmptyStringToNotEmpty mutation that would change '' to 'PEST Mutator was here!'
    $project = ProjectData::fromModelOrArray([]);

    expect($project->proj_name)->toBe('');
    expect($project->proj_short_name)->toBe('');
    expect($project->id)->toBe('');
});

test('missing proj_name in nested raw_data defaults to empty string', function () {
    $project = ProjectData::fromModelOrArray(['raw_data' => ['proj_id' => '1']]);

    expect($project->proj_name)->toBe('');
});

// ---------------------------------------------------------------
// Object path with null raw_data — asserts raw_data is [] not scalar
// ---------------------------------------------------------------

test('object with null raw_data: raw_data is empty array (not null)', function () {
    $obj = new stdClass;
    $obj->id = 'X';
    $obj->raw_data = null;

    $project = ProjectData::fromModelOrArray($obj);

    // Kills CoalesceRemoveLeft that would make raw_data = null-derived empty
    expect($project->raw_data)->toBe([]);
});

test('object with scalar raw_data is cast to non-empty array', function () {
    $obj = new stdClass;
    $obj->id = 'X';
    $obj->raw_data = 'raw-string-value';

    $project = ProjectData::fromModelOrArray($obj);

    // (array) 'raw-string-value' = [0 => 'raw-string-value'] — non-empty
    // Kills CoalesceRemoveLeft that would make it (array)[]
    expect($project->raw_data)->not->toBeEmpty()
        ->and($project->raw_data[0])->toBe('raw-string-value');
});

// ---------------------------------------------------------------
// Array path — id is cast to string (kills RemoveStringCast)
// ---------------------------------------------------------------

test('array path: integer id is cast to string', function () {
    $project = ProjectData::fromModelOrArray(['id' => 100, 'proj_short_name' => 'P', 'proj_name' => 'P']);

    expect($project->id)->toBe('100')
        ->and($project->id)->toBeString();
});
