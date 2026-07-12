<?php

use TeckFuture\P6Analyzer\IO\ScheduleQueryEngine;
use TeckFuture\P6Analyzer\IO\XerParser;

beforeEach(function () {
    $this->data = (new XerParser)->parseFile($this->fixturePath());
    $this->engine = new ScheduleQueryEngine($this->data);
});

test('listProjects returns projects', function () {
    $projects = $this->engine->listProjects();
    expect($projects)->not->toBeEmpty();
});

test('listActivities returns all activities', function () {
    $activities = $this->engine->listActivities();
    expect($activities)->not->toBeEmpty();
});

test('getActivity finds by task_code', function () {
    $allActivities = $this->engine->listActivities();
    $firstCode = $allActivities->first()->task_code;

    $activity = $this->engine->getActivity($firstCode);
    expect($activity)->not->toBeNull()
        ->and($activity->task_code)->toBe($firstCode);
});

test('getActivity returns null for nonexistent', function () {
    $activity = $this->engine->getActivity('NONEXISTENT_CODE_XYZ');
    expect($activity)->toBeNull();
});

test('searchActivities finds partial matches', function () {
    $allActivities = $this->engine->listActivities();
    $firstName = $allActivities->first()->name;

    if (strlen($firstName) >= 3) {
        $partial = substr($firstName, 0, 3);
        $results = $this->engine->searchActivities($partial);
        expect($results)->not->toBeEmpty();
    } else {
        $this->markTestSkipped('Activity name too short for partial search test');
    }
});

test('searchActivities returns empty for no match', function () {
    $results = $this->engine->searchActivities('ZZZZNONEXISTENT');
    expect($results)->toBeEmpty();
});
