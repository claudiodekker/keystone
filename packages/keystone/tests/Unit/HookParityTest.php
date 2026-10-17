<?php

// Class loading, no parser: each core controller is found by its file, its hooks are its send* methods.

use ClaudioDekker\Keystone\Tests\Fixtures\AppTestCase;

dataset('controllers', function () {
    return collect(glob(dirname(__DIR__, 2).'/src/Http/Controllers/*.php'))
        ->map(fn (string $path) => basename($path, '.php'))
        ->mapWithKeys(fn (string $name) => [$name => [$name]])
        ->all();
});

it('has an assertion for every response hook of a core controller', function (string $controller) {
    $hooks = collect((new ReflectionClass("ClaudioDekker\\Keystone\\Http\\Controllers\\{$controller}"))->getMethods())
        ->map(fn (ReflectionMethod $method) => $method->getName())
        ->filter(fn (string $name) => str_starts_with($name, 'send'));

    $missing = $hooks->reject(fn (string $hook) => method_exists(AppTestCase::class, 'assert'.substr($hook, 4)));

    expect($hooks)->not->toBeEmpty()
        ->and($missing->values()->all())->toBe([]);
})->with('controllers');

it('has a fixture subclass of every core controller that implements every hook', function (string $controller) {
    $fixture = new ReflectionClass("ClaudioDekker\\Keystone\\Tests\\Fixtures\\Http\\Controllers\\{$controller}");

    expect($fixture->isSubclassOf("ClaudioDekker\\Keystone\\Http\\Controllers\\{$controller}"))->toBeTrue()
        ->and($fixture->isInstantiable())->toBeTrue();
})->with('controllers');
