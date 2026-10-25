<?php

use ClaudioDekker\Keystone\Methods\CredentialTypeRegistry;
use ClaudioDekker\Keystone\Methods\CredentialTypes;

// Core's internal services are autowired and never bound, so no app can swap one,
// and only core's wiring decides them. The credential types are the exception,
// which core binds itself to hold the types that method packages register.

dataset('internal classes', function () {
    $source = dirname(__DIR__, 2).'/src';

    return collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)))
        ->map(fn (SplFileInfo $file) => 'ClaudioDekker\\Keystone\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($source) + 1, -4)))
        ->filter(fn (string $class) => str_contains((string) (new ReflectionClass($class))->getDocComment(), '@internal'))
        ->reject(fn (string $class) => in_array($class, [CredentialTypeRegistry::class, CredentialTypes::class], true))
        ->sort()
        ->mapWithKeys(fn (string $class) => [$class => [$class]])
        ->all();
});

it('binds none of its internal classes in the container', function (string $class) {
    expect($this->app->bound($class))->toBeFalse();
})->with('internal classes');
