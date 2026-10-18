<?php

use ClaudioDekker\Keystone\Tests\CheckScript;
use ClaudioDekker\Keystone\Tests\Monorepo;
use Illuminate\Support\Str;

it('requires every package but core in require-dev, at the version of the repository', function () {
    $required = collect(Monorepo::packagesExceptCore())
        ->mapWithKeys(fn (string $package) => [Monorepo::manifest($package)['name'] => 'self.version'])
        ->all();

    expect(Monorepo::manifest()['require-dev'])->toMatchArray($required);
});

it("maps every package's AppTests in autoload-dev, under the package's own namespace", function (string $package) {
    $namespace = array_key_first(Monorepo::manifest($package)['autoload']['psr-4']);

    expect(Monorepo::manifest()['autoload-dev']['psr-4'])->toMatchArray([
        $namespace.'AppTests\\' => "packages/{$package}/app-tests/",
    ]);
})->with(fn () => Monorepo::packages());

it("maps every adapter's AppTests in autoload-dev, under Keystone's namespace and named after the adapter", function (string $adapter) {
    $name = Str::studly(Str::after($adapter, 'keystone-'));

    expect(Monorepo::appTestsNamespace($adapter))->toBe("ClaudioDekker\\Keystone\\{$name}\\AppTests\\");
})->with(fn () => Monorepo::adapters());

it("maps every adapter's stubs in autoload-dev, as the app's own namespaces", function (string $adapter) {
    expect(Monorepo::manifest()['autoload-dev']['psr-4'])->toMatchArray([
        'App\\' => "packages/{$adapter}/stubs/app/",
        'Tests\\Keystone\\' => "packages/{$adapter}/stubs/tests/Keystone/",
    ]);
})->with(fn () => Monorepo::adapters());

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

it("runs every package's AppTests through every adapter's stubs in composer check", function (string $adapter) {
    $steps = CheckScript::steps();
    $switch = array_search("@putenv KEYSTONE_ADAPTER={$adapter}", $steps, true);

    expect($switch)->not->toBeFalse()
        ->and($steps[$switch + 1])->toBe('pest packages/*/app-tests');
})->with(fn () => Monorepo::adapters());

it('runs only the contract AppTests and its own suite in the CI job of every method package', function (string $package) {
    expect(Monorepo::commands($package))->toContain("vendor/bin/pest --testsuite=contract,{$package}");
})->with(fn () => Monorepo::methodPackages());

it('runs only the own suite of every adapter, without the method packages, in its CI job', function (string $adapter) {
    $commands = Monorepo::commands($adapter);
    $removed = collect($commands)->first(fn (string $command) => str_starts_with($command, 'composer remove --dev'));
    $names = collect(Monorepo::methodPackages())->map(fn (string $package) => Monorepo::manifest($package)['name'])->all();

    foreach ($names as $name) {
        expect($removed)->toContain($name);
    }

    expect($commands)->toContain("vendor/bin/pest --testsuite={$adapter}");
})->with(fn () => Monorepo::adapters());

it('removes every package but core in the CI job that runs core alone', function () {
    $commands = Monorepo::commands('core-alone');
    $removed = collect($commands)->first(fn (string $command) => str_starts_with($command, 'composer remove --dev'));
    $names = collect(Monorepo::packagesExceptCore())
        ->map(fn (string $package) => Monorepo::manifest($package)['name'])
        ->all();

    foreach ($names as $name) {
        expect($removed)->toContain($name);
    }
});

it("runs every suite of core in the CI job that runs core alone, and no other package's", function () {
    $core = Monorepo::suites()
        ->keys()
        ->reject(fn (string $name) => in_array($name, [...Monorepo::methodPackages(), ...Monorepo::adapters(), 'tooling'], true))
        ->implode(',');

    expect(Monorepo::commands('core-alone'))->toContain("vendor/bin/pest --testsuite={$core}");
});
