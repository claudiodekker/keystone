<?php

use Illuminate\Support\Str;

const ADAPTER_CONTROLLERS_NAMESPACE = 'App\Http\Controllers\Keystone\\';

function coreControllers(): array
{
    $files = glob(dirname(__DIR__).'/packages/keystone/src/Http/Controllers/*Controller.php');

    $names = array_map(fn (string $file) => basename($file, '.php'), $files);

    return array_combine($names, array_map(fn (string $name) => 'ClaudioDekker\Keystone\Http\Controllers\\'.$name, $names));
}

function responseHooks(string $controller): array
{
    $methods = (new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_ABSTRACT);
    $names = array_map(fn (ReflectionMethod $method) => $method->getName(), $methods);

    return array_values(preg_grep('/^send/', $names));
}

test('every core controller has a subclass in the Inertia-Vue stubs that implements every hook', function (string $controller) {
    $stub = ADAPTER_CONTROLLERS_NAMESPACE.class_basename($controller);

    expect(is_subclass_of($stub, $controller))->toBeTrue()
        ->and((new ReflectionClass($stub))->isAbstract())->toBeFalse();
})->with(coreControllers());

test('every response hook has an assertion', function (string $controller) {
    $trait = 'ClaudioDekker\Keystone\AppTests\Assertions\\'.Str::replaceLast('Controller', 'Assertions', class_basename($controller));
    $hooks = responseHooks($controller);

    $unasserted = array_filter($hooks, fn (string $hook) => ! method_exists($trait, 'assert'.Str::after($hook, 'send')));

    expect($hooks)->not->toBeEmpty()
        ->and(array_values($unasserted))->toBe([]);
})->with(coreControllers());
