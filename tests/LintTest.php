<?php

dataset('checks', function () {
    return collect(glob(__DIR__.'/../lint/Checks/*.php'))
        ->map(fn (string $path) => basename($path, '.php'))
        ->mapWithKeys(fn (string $check) => [$check => [$check]])
        ->all();
});

it('holds over the repository', function (string $check) {
    $check = "ClaudioDekker\\Keystone\\Lint\\Checks\\{$check}";

    expect((new $check)->run())->toBe([]);
})->with('checks');

it('has a sample test for every check', function (string $check) {
    expect(__DIR__."/Lint/{$check}Test.php")->toBeFile();
})->with('checks');
