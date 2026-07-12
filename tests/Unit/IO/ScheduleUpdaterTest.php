<?php

use TeckFuture\P6Analyzer\Exceptions\XerParseException;
use TeckFuture\P6Analyzer\IO\ScheduleUpdater;
use TeckFuture\P6Analyzer\IO\XerParser;

beforeEach(function () {
    $this->data = (new XerParser)->parseFile($this->fixturePath());
    $this->updater = new ScheduleUpdater;
});

test('updateActivity updates an existing task', function () {
    $firstTask = $this->data->getTable('TASK')->first();
    $taskId = $firstTask['task_id'];

    $result = $this->updater->updateActivity($this->data, $taskId, ['status_code' => 'TK_Complete']);

    expect($result)->toBeTrue();

    $updated = $this->data->getTable('TASK')->first(fn ($row) => $row['task_id'] === $taskId);
    expect($updated['status_code'])->toBe('TK_Complete');
});

test('updateActivity returns false for nonexistent task', function () {
    $result = $this->updater->updateActivity($this->data, 'NONEXISTENT', ['status_code' => 'TK_Complete']);
    expect($result)->toBeFalse();
});

test('batchUpdate updates multiple tasks', function () {
    $tasks = $this->data->getTable('TASK')->take(2);
    $updates = $tasks->map(fn ($row) => [
        'task_id' => $row['task_id'],
        'updates' => ['status_code' => 'TK_Complete'],
    ])->values()->toArray();

    $count = $this->updater->batchUpdate($this->data, $updates);
    expect($count)->toBe(2);
});

test('addActivity throws when task_id is missing', function () {
    $this->updater->addActivity($this->data, ['task_name' => 'No ID']);
})->throws(XerParseException::class);

test('addActivity adds a new row to TASK table', function () {
    $originalCount = $this->data->getTable('TASK')->count();

    $this->updater->addActivity($this->data, [
        'task_id' => '99999',
        'task_code' => 'NEW01',
        'task_name' => 'New Task',
    ]);

    expect($this->data->getTable('TASK')->count())->toBe($originalCount + 1);
});
