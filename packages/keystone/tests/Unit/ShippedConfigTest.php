<?php

use Illuminate\Support\Arr;

dataset('config reads', function () {
    $source = dirname(__DIR__, 2).'/src';
    $reads = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        $code = file_get_contents($file->getPathname());

        preg_match_all("/(?:\\bconfig\\(|Config::(?:get|has|string|integer|boolean|array|float)\\(|->(?:get|has|string|integer|boolean|array|float)\\()\\s*'keystone\\.([^']+)'/", $code, $matches);

        foreach ($matches[1] as $key) {
            $reads[$key] = [$key];
        }
    }

    ksort($reads);

    return $reads;
});

it('ships a default for every key that core reads', function (string $key) {
    $shipped = require dirname(__DIR__, 2).'/config/keystone.php';
    $parent = rtrim($key, '.');

    expect(Arr::has($shipped, $parent))->toBeTrue();
})->with('config reads');
