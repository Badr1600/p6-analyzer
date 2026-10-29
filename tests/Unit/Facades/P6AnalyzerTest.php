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
