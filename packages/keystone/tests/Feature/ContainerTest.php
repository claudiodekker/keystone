<?php

// Core's internal services are autowired and never bound, so an app can't swap one; only core's own wiring decides that.

dataset('internal classes', function () {
    $source = dirname(__DIR__, 2).'/src';

    return collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)))
        ->map(fn (SplFileInfo $file) => 'ClaudioDekker\\Keystone\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($source) + 1, -4)))
        ->filter(fn (string $class) => str_contains((string) (new ReflectionClass($class))->getDocComment(), '@internal'))
        ->sort()
        ->mapWithKeys(fn (string $class) => [$class => [$class]])
        ->all();
});

it('binds none of its internal classes in the container', function (string $class) {
    expect($this->app->bound($class))->toBeFalse();
})->with('internal classes');
