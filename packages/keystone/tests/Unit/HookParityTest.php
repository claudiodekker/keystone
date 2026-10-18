<?php

// Class loading, no parser: each core controller is found by its file, its hooks are its send* methods.

use ClaudioDekker\Keystone\Tests\Fixtures\AppTestCase;
use ClaudioDekker\Keystone\Tests\Monorepo;

dataset('controllers', function () {
    return collect(glob(dirname(__DIR__, 2).'/src/Http/Controllers/*.php'))
        ->map(fn (string $path) => basename($path, '.php'))
        ->mapWithKeys(fn (string $name) => [$name => [$name]])
        ->all();
});

dataset('app controllers', function () {
    return collect(Monorepo::installedAdapters())
        ->mapWithKeys(fn (string $adapter) => [$adapter => ['App\\Http\\Controllers\\Auth\\']])
        ->prepend(['ClaudioDekker\\Keystone\\Tests\\Fixtures\\Http\\Controllers\\'], 'fixtures')
        ->all();
});

dataset('app test cases', function () {
    return collect(Monorepo::installedAdapters())
        ->mapWithKeys(fn (string $adapter) => [$adapter => ['Tests\\Keystone\\TestCase']])
        ->prepend([AppTestCase::class], 'fixtures')
        ->all();
});

dataset('assertion traits', function () {
    return collect(glob(dirname(__DIR__, 2).'/app-tests/Assertions/*.php'))
        ->map(fn (string $path) => basename($path, '.php'))
        ->mapWithKeys(fn (string $name) => [$name => [$name]])
        ->all();
});

it('has an assertion for every response hook of a core controller', function (string $controller, string $testCase) {
    $hooks = collect((new ReflectionClass("ClaudioDekker\\Keystone\\Http\\Controllers\\{$controller}"))->getMethods())
        ->map(fn (ReflectionMethod $method) => $method->getName())
        ->filter(fn (string $name) => str_starts_with($name, 'send'));

    $missing = $hooks->reject(fn (string $hook) => method_exists($testCase, 'assert'.substr($hook, 4)));

    expect($hooks)->not->toBeEmpty()
        ->and($missing->values()->all())->toBe([]);
})->with('controllers', 'app test cases');

it('has a concrete subclass of every core controller that implements every hook', function (string $controller, string $controllers) {
    $subclass = new ReflectionClass($controllers.$controller);

    expect($subclass->isSubclassOf("ClaudioDekker\\Keystone\\Http\\Controllers\\{$controller}"))->toBeTrue()
        ->and($subclass->isInstantiable())->toBeTrue();
})->with('controllers', 'app controllers');

it("reaches every assertion trait in every app, through the adapter's own trait where it has one", function (string $trait) {
    $core = "ClaudioDekker\\Keystone\\AppTests\\Assertions\\{$trait}";

    expect(class_uses_recursive(AppTestCase::class))->toContain($core);

    foreach (Monorepo::installedAdapters() as $adapter) {
        $copy = "Tests\\Keystone\\Assertions\\{$trait}";
        $own = Monorepo::appTestsNamespace($adapter)."Assertions\\{$trait}";

        expect(class_uses('Tests\\Keystone\\TestCase'))->toContain($copy)
            ->and(class_uses($copy))->toContain(trait_exists($own) ? $own : $core);
    }
})->with('assertion traits');
