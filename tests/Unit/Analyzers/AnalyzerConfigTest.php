<?php

use Illuminate\Container\Container;
use TeckFuture\P6Analyzer\Analyzers\CriticalPathCalculator;
use TeckFuture\P6Analyzer\Analyzers\DCMA14PointChecker;
use TeckFuture\P6Analyzer\Analyzers\EarnedValueCalculator;
use TeckFuture\P6Analyzer\Analyzers\ScheduleVarianceCalculator;
use TeckFuture\P6Analyzer\DTOs\ActivityData;
use TeckFuture\P6Analyzer\Enums\EvmMethod;
use TeckFuture\P6Analyzer\P6AnalyzerManager;

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------

function makeConfigActivity(array $row = []): ActivityData
{
    return ActivityData::fromModelOrArray(array_merge([
        'task_id' => uniqid(),
        'task_code' => uniqid('A'),
        'status_code' => 'TK_Active',
        'target_drtn_hr_cnt' => '40',
    ], $row));
}

/**
 * Run a callback the way standalone code outside a booted Laravel application
 * would: the Illuminate helpers exist, but the container behind config() is empty.
 */
function withUnresolvableConfig(Closure $callback): mixed
{
    $app = Container::getInstance();
    Container::setInstance(new Container);

    try {
        return $callback();
    } finally {
        Container::setInstance($app);
    }
}

// ---------------------------------------------------------------
// Standalone use: no config, no exception
// ---------------------------------------------------------------

dataset('config-dependent manager calls', [
    'criticalPath()' => [fn (P6AnalyzerManager $manager) => $manager->criticalPath()],
    'dcma14Point()' => [fn (P6AnalyzerManager $manager) => $manager->dcma14Point('2024-01-15')],
    'earnedValue()' => [fn (P6AnalyzerManager $manager) => $manager->earnedValue()],
    'scheduleVariance()' => [fn (P6AnalyzerManager $manager) => $manager->scheduleVariance($manager->getData())],
    'resourceUtilization()' => [fn (P6AnalyzerManager $manager) => $manager->resourceUtilization()],
]);

test('it uses the defaults when config cannot be resolved', function (Closure $call) {
    $manager = (new P6AnalyzerManager)->load($this->fixturePath());
    $withDefaultConfig = $call($manager);

    expect(withUnresolvableConfig(fn () => $call($manager)))->toBe($withDefaultConfig);
})->with('config-dependent manager calls');

// ---------------------------------------------------------------
// Each setting reaches its analyzer
// ---------------------------------------------------------------

test('critical_float_threshold sets the default critical path threshold', function () {
    $activities = collect(['0', '8', '16', '24'])
        ->map(fn (string $floatHr) => makeConfigActivity(['total_float_hr_cnt' => $floatHr]));

    $default = (new CriticalPathCalculator)->calculate($activities);

    config(['p6-analyzer.critical_float_threshold' => 16]);
    $configured = (new CriticalPathCalculator)->calculate($activities);

    expect($default['critical_activities_count'])->toBe(1)
        ->and($configured['critical_activities_count'])->toBe(3);
});

test('the DCMA high float and high duration thresholds come from config', function () {
    $activities = collect([makeConfigActivity(['total_float_hr_cnt' => '100', 'target_drtn_hr_cnt' => '120'])]);

    $default = (new DCMA14PointChecker)->calculate($activities);

    config(['p6-analyzer.high_float_threshold' => 80, 'p6-analyzer.high_duration_threshold' => 100]);
    $configured = (new DCMA14PointChecker)->calculate($activities);

    expect($default['high_float_count'])->toBe(0)
        ->and($default['high_duration_count'])->toBe(0)
        ->and($configured['high_float_count'])->toBe(1)
        ->and($configured['high_duration_count'])->toBe(1);
});

test('the DCMA penalty weights come from config', function () {
    // One of ten incomplete activities (10%) has negative float
    $activities = collect(range(1, 10))
        ->map(fn (int $i) => makeConfigActivity(['total_float_hr_cnt' => $i === 1 ? '-8' : '0']));

    $default = (new DCMA14PointChecker)->calculate($activities);

    config(['p6-analyzer.dcma_penalty_weights' => ['negative_float' => 5]]);
    $configured = (new DCMA14PointChecker)->calculate($activities);

    expect($default['overall_score'])->toBe(80.0)    // 100 - 10% x 2
        ->and($configured['overall_score'])->toBe(50.0); // 100 - 10% x 5
});

test('min_slip_days sets the default slip threshold of the schedule variance', function () {
    $baseline = collect([makeConfigActivity(['task_code' => 'A1', 'target_end_date' => '2024-06-01'])]);
    $current = collect([makeConfigActivity(['task_code' => 'A1', 'target_end_date' => '2024-06-08'])]); // 7 days late

    $default = (new ScheduleVarianceCalculator)->calculate($current, $baseline);

    config(['p6-analyzer.min_slip_days' => 10]);
    $configured = (new ScheduleVarianceCalculator)->calculate($current, $baseline);

    expect($default['slipping_activities_count'])->toBe(1)
        ->and($configured['slipping_activities_count'])->toBe(0);
});

test('evm.default_method sets the method earnedValue() uses when none is passed', function () {
    $manager = (new P6AnalyzerManager)->load($this->fixturePath());

    $default = $manager->earnedValue();

    config(['p6-analyzer.evm.default_method' => 'duration']);

    expect($default['method'])->toBe('cost')
        ->and($manager->earnedValue()['method'])->toBe('duration')
        ->and($manager->earnedValue(null, EvmMethod::Cost)['method'])->toBe('cost');
});

test('evm options passed to the constructor win over the evm config', function () {
    // BAC 100 with 40 remaining and nothing tracked: actual cost is inferred as 60 when inference is on
    $activities = collect([makeConfigActivity(['target_cost' => '100', 'remain_cost' => '40', 'phys_comp_pct' => '60'])]);
    $actualCost = fn (array $options) => (new EarnedValueCalculator($options))
        ->calculate($activities, null, EvmMethod::Cost)['actual_cost'];

    expect($actualCost([]))->toBe(60.0);

    config(['p6-analyzer.evm.infer_actuals_from_remaining' => false]);

    expect($actualCost([]))->toBe(0.0)
        ->and($actualCost(['infer_actuals_from_remaining' => true]))->toBe(60.0);
});
