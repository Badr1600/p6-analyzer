<?php

use Illuminate\Support\Collection;
use TeckFuture\P6Analyzer\Facades\P6Analyzer;
use TeckFuture\P6Analyzer\P6AnalyzerManager;

covers(P6Analyzer::class);

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------

/**
 * The public methods of the manager the facade has to proxy.
 *
 * @return Collection<int, ReflectionMethod>
 */
function managerPublicMethods(): Collection
{
    return collect((new ReflectionClass(P6AnalyzerManager::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method) => $method->isConstructor());
}

/**
 * The facade's `@method static <return> name(<parameters>)` lines, keyed by method name.
 *
 * @return Collection<string, string> The documented signature as `<return> name(<parameters>)`.
 */
function facadeDocumentedSignatures(): Collection
{
    $docblock = (new ReflectionClass(P6Analyzer::class))->getDocComment();
    preg_match_all('/@method\s+static\s+(\S+)\s+(\w+)\((.*)\)\s*$/m', $docblock, $lines, PREG_SET_ORDER);

    return collect($lines)->mapWithKeys(fn (array $line) => [$line[2] => normalizedSignature("{$line[1]} {$line[2]}({$line[3]})")]);
}

/** The manager method's signature in the same notation as the facade docblock. */
function managerSignature(ReflectionMethod $method): string
{
    $parameters = array_map(function (ReflectionParameter $parameter) {
        $type = $parameter->hasType() ? "{$parameter->getType()} " : '';
        $default = $parameter->isDefaultValueAvailable() ? ' = '.exportedDefault($parameter->getDefaultValue()) : '';

        return "{$type}\${$parameter->getName()}{$default}";
    }, $method->getParameters());

    return normalizedSignature("{$method->getReturnType()} {$method->getName()}(".implode(', ', $parameters).')');
}

function exportedDefault(mixed $value): string
{
    return match (true) {
        $value === null => 'null',
        $value === [] => '[]',
        default => var_export($value, true),
    };
}

/** Class names compare by short name, and a `self` return is documented as the manager's own name. */
function normalizedSignature(string $signature): string
{
    $signature = preg_replace('/\\\\?(?:\w+\\\\)+(\w+)/', '$1', $signature);

    return preg_replace('/^self /', 'P6AnalyzerManager ', preg_replace('/\s+/', ' ', trim($signature)));
}

// ---------------------------------------------------------------
// The @method block
// ---------------------------------------------------------------

test('the facade documents exactly the public methods of the manager', function () {
    $managerMethods = managerPublicMethods()->map(fn (ReflectionMethod $method) => $method->getName());

    expect($managerMethods)->not->toBeEmpty()
        ->and(facadeDocumentedSignatures()->keys()->all())->toEqualCanonicalizing($managerMethods->all());
});

test('the facade documents each manager method with its exact parameters, defaults and return type', function () {
    $managerSignatures = managerPublicMethods()
        ->mapWithKeys(fn (ReflectionMethod $method) => [$method->getName() => managerSignature($method)])
        ->sortKeys();

    expect(facadeDocumentedSignatures()->sortKeys()->all())->toBe($managerSignatures->all());
});

// ---------------------------------------------------------------
// A fresh manager for every call
// ---------------------------------------------------------------

test('a facade call without load() does not see data loaded by an earlier call', function () {
    // One test body on purpose: Testbench clears the resolved facade instances between tests.
    expect(P6Analyzer::load($this->fixturePath())->criticalPath())->toHaveKey('total_activities');

    P6Analyzer::criticalPath();
})->throws(RuntimeException::class, 'No XER data loaded');

test('two facade chains do not share loaded data', function () {
    $first = P6Analyzer::load($this->fixturePath());
    $second = P6Analyzer::parse(file_get_contents($this->fixturePath()));

    expect($second)->not->toBe($first)
        ->and($second->getData())->not->toBe($first->getData());
});

test('the container resolves a new manager every time', function () {
    expect(app(P6AnalyzerManager::class))->not->toBe(app(P6AnalyzerManager::class))
        ->and(app('p6-analyzer'))->not->toBe(app('p6-analyzer'))
        ->and(app('p6-analyzer'))->toBeInstanceOf(P6AnalyzerManager::class);
});

test('a manager swapped into the facade is still the one it calls', function () {
    $manager = (new P6AnalyzerManager)->load($this->fixturePath());

    P6Analyzer::swap($manager);

    expect(P6Analyzer::getData())->toBe($manager->getData());
});
