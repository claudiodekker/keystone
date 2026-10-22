<?php

use ClaudioDekker\Keystone\Http\Middleware\CaptureRequestContext;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;

dataset('classes core injects', function () {
    $source = dirname(__DIR__, 3).'/src';
    $injected = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        $class = 'ClaudioDekker\\Keystone\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($source) + 1, -4));
        $reflection = new ReflectionClass($class);
        $methods = str_contains($class, '\\Http\\Controllers\\')
            ? $reflection->getMethods(ReflectionMethod::IS_PUBLIC)
            : array_filter([$reflection->getConstructor()]);

        foreach ($methods as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                $name = $type instanceof ReflectionNamedType ? $type->getName() : '';

                if (! str_starts_with($name, 'ClaudioDekker\\Keystone\\') || enum_exists($name)) {
                    continue;
                }

                $injectedClass = new ReflectionClass($name);

                if (! $injectedClass->isReadOnly() && ! $injectedClass->isSubclassOf(Model::class)) {
                    $injected[$name] = [$name, $class];
                }
            }
        }
    }

    ksort($injected);

    return $injected;
});

dataset('middleware core runs', function () {
    $source = dirname(__DIR__, 3).'/src/Http/Middleware';

    return collect(glob($source.'/*.php'))
        ->map(fn (string $path) => 'ClaudioDekker\\Keystone\\Http\\Middleware\\'.basename($path, '.php'))
        ->mapWithKeys(fn (string $class) => [$class => [$class]])
        ->all();
});

it('boots with none of its internal services bound', function () {
    expect($this->bootFailures())->toBe([]);
});

it('refuses an app that binds a class core injects', function (string $class, string $injectedInto) {
    $this->app->bind($class, $class);

    expect($this->bootFailures())->toBe(["The app binds [{$class}], one of Keystone's internal services. Remove the binding, since Keystone makes its security decisions with its own."]);
})->with('classes core injects');

it('refuses an app that binds a middleware core runs', function (string $class) {
    $this->app->bind($class, $class);

    expect($this->bootFailures())->toBe(["The app binds [{$class}], one of Keystone's internal services. Remove the binding, since Keystone makes its security decisions with its own."]);
})->with('middleware core runs');

it('refuses an app that keeps an instance of an internal service', function () {
    $this->app->instance(Recorder::class, new stdClass);

    expect($this->bootFailures())->toBe(['The app binds ['.Recorder::class."], one of Keystone's internal services. Remove the binding, since Keystone makes its security decisions with its own."]);
});

it('refuses an HTTP kernel that does not run the request context middleware', function () {
    $this->app->make(Kernel::class)->setGlobalMiddleware([TrustProxies::class]);

    expect($this->bootFailures())->toBe(["The HTTP kernel doesn't run [".CaptureRequestContext::class.'] as global middleware, which Keystone needs to read the request. Add it back to the global middleware.']);
});
