<?php

use ClaudioDekker\Keystone\Tests\Monorepo;

it('requires every package but core in require-dev, at the version of the repository', function () {
    $required = collect(Monorepo::methodPackages())
        ->mapWithKeys(fn (string $package) => [Monorepo::manifest($package)['name'] => 'self.version'])
        ->all();

    expect(Monorepo::manifest()['require-dev'])->toMatchArray($required);
});

it('maps the AppTests of every package in autoload-dev', function (string $package) {
    $namespace = array_key_first(Monorepo::manifest($package)['autoload']['psr-4']);

    expect(Monorepo::manifest()['autoload-dev']['psr-4'])->toMatchArray([
        $namespace.'AppTests\\' => "packages/{$package}/app-tests/",
    ]);
})->with(fn () => Monorepo::packages());

it('gives every package a testsuite of its own tests, named after it', function (string $package) {
    $suite = Monorepo::suites()->get($package);

    expect($suite)->not->toBeNull()
        ->and(Monorepo::directories($suite))->toContain("packages/{$package}/tests");
})->with(fn () => Monorepo::packages());

it('gives a method package its AppTests in its own testsuite', function (string $package) {
    expect(Monorepo::directories(Monorepo::suites()->get($package)))->toContain("packages/{$package}/app-tests");
})->with(fn () => Monorepo::methodPackages());

it("keeps core's flow AppTests out of every testsuite but app-tests", function () {
    $flows = Monorepo::suites()->get('app-tests');
    $readsOfCore = Monorepo::suites()
        ->except('app-tests')
        ->map(fn (SimpleXMLElement $suite) => collect(Monorepo::directories($suite))
            ->filter(fn (string $directory) => str_starts_with($directory, 'packages/keystone/app-tests'))
            ->values()
            ->all())
        ->reject(fn (array $directories) => in_array($directories, [[], ['packages/keystone/app-tests/Contract']], true));

    expect(Monorepo::directories($flows))->toBe(['packages/keystone/app-tests'])
        ->and(Monorepo::excluded($flows))->toBe(['packages/keystone/app-tests/Contract'])
        ->and($readsOfCore->keys()->all())->toBe([]);
});

it('runs only the contract AppTests and its own suite in the CI job of every method package', function (string $package) {
    expect(Monorepo::commands($package))->toContain("vendor/bin/pest --testsuite=contract,{$package}");
})->with(fn () => Monorepo::methodPackages());

it('removes every method package in the CI job that runs core alone', function () {
    $commands = Monorepo::commands('core-alone');
    $removed = collect($commands)->first(fn (string $command) => str_starts_with($command, 'composer remove --dev'));
    $names = collect(Monorepo::methodPackages())->map(fn (string $package) => Monorepo::manifest($package)['name'])->all();

    foreach ($names as $name) {
        expect($removed)->toContain($name);
    }
});

it("runs every suite of core in the CI job that runs core alone, and no method package's", function () {
    $core = Monorepo::suites()
        ->keys()
        ->reject(fn (string $name) => in_array($name, [...Monorepo::methodPackages(), 'tooling'], true))
        ->implode(',');

    expect(Monorepo::commands('core-alone'))->toContain("vendor/bin/pest --testsuite={$core}");
});
