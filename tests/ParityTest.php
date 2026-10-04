<?php

use Illuminate\Support\Str;

const CORE_CONTROLLERS_NAMESPACE = 'ClaudioDekker\Keystone\Http\Controllers\\';

const ADAPTER_CONTROLLERS_NAMESPACE = 'App\Http\Controllers\Auth\\';

function coreControllers(): array
{
    $directory = dirname(__DIR__).'/packages/keystone/src/Http/Controllers';
    $tree = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    $names = [];

    foreach ($tree as $file) {
        $path = substr($file->getPathname(), strlen($directory) + 1);

        if (str_ends_with($path, 'Controller.php')) {
            $names[] = str_replace('/', '\\', substr($path, 0, -strlen('.php')));
        }
    }

    $names = array_values(array_diff($names, ['Controller']));
    sort($names);

    return array_combine($names, array_map(fn (string $name) => CORE_CONTROLLERS_NAMESPACE.$name, $names));
}

function responseHooks(string $controller): array
{
    $methods = (new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_ABSTRACT);
    $names = array_map(fn (ReflectionMethod $method) => $method->getName(), $methods);

    return array_values(preg_grep('/^send/', $names));
}

test('every core controller has a subclass in the Inertia-Vue stubs that implements every hook', function (string $controller) {
    $stub = ADAPTER_CONTROLLERS_NAMESPACE.substr($controller, strlen(CORE_CONTROLLERS_NAMESPACE));

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
