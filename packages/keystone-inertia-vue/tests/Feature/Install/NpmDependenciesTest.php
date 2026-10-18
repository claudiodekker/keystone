<?php

use ClaudioDekker\Keystone\InertiaVue\Tests\InstallsIntoFreshApp;

uses(InstallsIntoFreshApp::class);

beforeEach(function () {
    $this->adapterPackages = json_decode(file_get_contents(dirname(__DIR__, 3).'/package.json'), true)['dependencies'];
});

it("adds the adapter's npm packages to devDependencies, TypeScript 6 among them, sorted with the skeleton's", function () {
    $this->artisan('keystone:install')->assertSuccessful();

    $devDependencies = json_decode($this->fresh->contents('package.json'), true)['devDependencies'];

    expect($devDependencies)->toHaveKey('typescript', '^6')
        ->toHaveKey('vite')
        ->and(array_keys($devDependencies))->toBe(collect($devDependencies)->keys()->sort()->values()->all())
        ->and($this->fresh->contents('package.json'))->toContain('"@inertiajs/vue3": ');

    foreach ($this->adapterPackages as $package => $version) {
        expect($devDependencies)->toHaveKey($package, $version);
    }
});

it('keeps the version the app pins', function (string $section) {
    $manifest = json_decode($this->fresh->contents('package.json'), true);
    $manifest[$section]['typescript'] = '^5.9';
    $this->fresh->put('package.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

    $this->artisan('keystone:install')->assertSuccessful();

    $manifest = json_decode($this->fresh->contents('package.json'), true);
    $packages = array_merge($manifest['dependencies'] ?? [], $manifest['devDependencies']);

    expect($packages)->toHaveKey('typescript', '^5.9')
        ->toHaveKey('vue', $this->adapterPackages['vue'])
        ->and(array_intersect_key($manifest['dependencies'] ?? [], $manifest['devDependencies']))->toBe([]);
})->with([
    'in dependencies' => ['dependencies'],
    'in devDependencies' => ['devDependencies'],
]);

it('adds the packages and leaves every other line as the skeleton has it', function () {
    $before = explode("\n", $this->fresh->contents('package.json'));
    expect($before)->toContain('    "optionalDependencies": {');

    $this->artisan('keystone:install')->assertSuccessful();

    $after = explode("\n", $this->fresh->contents('package.json'));
    $removed = array_values(array_diff($before, $after));
    $added = array_values(array_diff($after, $before));

    expect($removed)->toHaveCount(1)
        ->and($added)->toContain($removed[0].',')
        ->and($added)->toHaveCount(count($this->adapterPackages) + 1);
});

it('prints the packages when devDependencies is laid out differently, and fails', function () {
    $this->fresh->put('package.json', $before = json_encode(json_decode($this->fresh->contents('package.json')), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

    $this->artisan('keystone:install')
        ->expectsOutputToContain('"typescript": "^6"')
        ->assertFailed();

    expect($this->fresh->contents('package.json'))->toBe($before);
});
