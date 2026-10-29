<?php

use TeckFuture\P6Analyzer\DTOs\ParsedXerData;
use TeckFuture\P6Analyzer\Exceptions\XerParseException;
use TeckFuture\P6Analyzer\IO\ScheduleUpdater;
use TeckFuture\P6Analyzer\IO\XerExporter;
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
    $taskIds = $tasks->pluck('task_id')->all();
    $statusesBefore = $tasks->pluck('status_code')->all();
    $otherStatusesBefore = $this->data->getTable('TASK')->slice(2)->pluck('status_code')->all();
    $updates = $tasks->map(fn ($row) => [
        'task_id' => $row['task_id'],
        'updates' => ['status_code' => 'TK_Complete'],
    ])->values()->toArray();

    $count = $this->updater->batchUpdate($this->data, $updates);

    $table = $this->data->getTable('TASK');

    // The targeted rows changed to TK_Complete and every other row kept its status
    expect($statusesBefore)->not->toContain('TK_Complete')
        ->and($count)->toBe(2)
        ->and($table->whereIn('task_id', $taskIds)->pluck('status_code')->values()->all())->toBe(['TK_Complete', 'TK_Complete'])
        ->and($table->slice(2)->pluck('status_code')->all())->toBe($otherStatusesBefore);
});

test('addActivity throws when task_id is missing', function () {
    $this->updater->addActivity($this->data, ['task_name' => 'No ID']);
})->throws(XerParseException::class);

test('addActivity throws when task_id is empty', function (?string $taskId) {
    $this->updater->addActivity($this->data, ['task_id' => $taskId, 'task_name' => 'No ID']);
})->throws(XerParseException::class, 'New activity must have a task_id')->with([
    'empty string' => [''],
    'null' => [null],
]);

test('addActivity accepts "0" as a task_id', function () {
    $originalCount = $this->data->getTable('TASK')->count();

    $this->updater->addActivity($this->data, ['task_id' => '0', 'task_name' => 'Zero']);

    $tasks = $this->data->getTable('TASK');

    expect($tasks)->toHaveCount($originalCount + 1)
        ->and($tasks->last()['task_id'])->toBe('0')
        ->and($tasks->last()['task_name'])->toBe('Zero');
});

test('addActivity throws when the task_id already exists, and adds nothing', function () {
    $existingId = $this->data->getTable('TASK')->first()['task_id'];
    $originalRows = $this->data->getTable('TASK')->all();

    expect(fn () => $this->updater->addActivity($this->data, ['task_id' => $existingId, 'task_name' => 'Duplicate']))
        ->toThrow(XerParseException::class, "An activity with task_id {$existingId} already exists");

    expect($this->data->getTable('TASK')->all())->toBe($originalRows);
});

test('addActivity throws when the task_id was added earlier', function () {
    $this->updater->addActivity($this->data, ['task_id' => '99999', 'task_name' => 'First']);
    $this->updater->addActivity($this->data, ['task_id' => '99999', 'task_name' => 'Second']);
})->throws(XerParseException::class, 'already exists');

test('addActivity compares task_id as a string, so an integer matches its string form', function () {
    $existingId = (int) $this->data->getTable('TASK')->first()['task_id'];

    $this->updater->addActivity($this->data, ['task_id' => $existingId]);
})->throws(XerParseException::class, 'already exists');

test('addActivity gives the new row the columns of the TASK table and ignores other keys', function () {
    $columns = array_keys($this->data->getTable('TASK')->first());

    $this->updater->addActivity($this->data, ['task_id' => '99999', 'task_name' => 'New Task', 'not_a_column' => 'x']);

    $added = $this->data->getTable('TASK')->last();

    expect(array_keys($added))->toBe($columns)
        ->and($added['task_name'])->toBe('New Task')
        ->and($added['task_code'])->toBe('');
});

test('addActivity creates the TASK table when the document has none', function () {
    $data = new ParsedXerData(header: "ERMHDR\t8.0", tables: collect());

    $this->updater->addActivity($data, ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'First']);

    expect($data->tables->has('TASK'))->toBeTrue()
        ->and($data->getTable('TASK')->all())->toBe([
            ['task_id' => '1', 'task_code' => 'A100', 'task_name' => 'First'],
        ]);
});

test('addActivity fills a TASK table that has no rows with the given columns', function () {
    $data = new ParsedXerData(header: "ERMHDR\t8.0", tables: collect(['TASK' => collect()]));

    $this->updater->addActivity($data, ['task_id' => '1', 'task_code' => 'A100']);

    expect($data->getTable('TASK')->all())->toBe([
        ['task_id' => '1', 'task_code' => 'A100'],
    ]);
});

test('an activity added to a document without a TASK table is exported', function () {
    $data = new ParsedXerData(header: "ERMHDR\t8.0", tables: collect());

    $this->updater->addActivity($data, ['task_id' => '1', 'task_name' => 'First']);

    expect((new XerExporter)->export($data))->toBe("ERMHDR\t8.0\n%T\tTASK\n%F\ttask_id\ttask_name\n%R\t1\tFirst\n%E\n");
});

test('addActivity adds a new row to TASK table', function () {
    $originalCount = $this->data->getTable('TASK')->count();

    $this->updater->addActivity($this->data, [
        'task_id' => '99999',
        'task_code' => 'NEW01',
        'task_name' => 'New Task',
    ]);

    $tasks = $this->data->getTable('TASK');
    $added = $tasks->firstWhere('task_id', '99999');

    expect($tasks->count())->toBe($originalCount + 1)
        ->and($added)->not->toBeNull()
        ->and($added['task_code'])->toBe('NEW01')
        ->and($added['task_name'])->toBe('New Task');
});
