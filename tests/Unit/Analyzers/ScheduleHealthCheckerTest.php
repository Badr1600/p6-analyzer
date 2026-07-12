<?php

use TeckFuture\P6Analyzer\Analyzers\ScheduleHealthChecker;
use TeckFuture\P6Analyzer\IO\XerParser;

test('it checks schedule health from real XER data', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new ScheduleHealthChecker)->check($data);

    expect($result)->toHaveKeys(['total_activities', 'missing_resources', 'missing_logic', 'out_of_sequence', 'lags_count', 'leads_count']);
});

test('it detects out of sequence with data date', function () {
    $data = (new XerParser)->parseFile($this->fixturePath());
    $result = (new ScheduleHealthChecker)->check($data, '2024-01-01');

    expect($result)->toHaveKey('out_of_sequence');
});
