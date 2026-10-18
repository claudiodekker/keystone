<?php

use ClaudioDekker\Keystone\InertiaVue\Tests\InstallsIntoFreshApp;

uses(InstallsIntoFreshApp::class);

it("adds the adapter's npm packages to devDependencies, TypeScript 6 among them, sorted with the skeleton's", function () {
    $adapterPackages = json_decode(file_get_contents(dirname(__DIR__, 3).'/package.json'), true)['dependencies'];

    $this->artisan('keystone:install')->assertSuccessful();

    $devDependencies = json_decode($this->fresh->contents('package.json'), true)['devDependencies'];

    expect($devDependencies)->toHaveKey('typescript', '^6')
        ->toHaveKey('vite')
        ->and(array_keys($devDependencies))->toBe(collect($devDependencies)->keys()->sort()->values()->all())
        ->and($this->fresh->contents('package.json'))->toContain('"@inertiajs/vue3": ');

    foreach (array_keys($adapterPackages) as $package) {
        expect($devDependencies)->toHaveKey($package);
    }
});

it('keeps the version the app pins', function (string $section) {
    $adapterPackages = json_decode(file_get_contents(dirname(__DIR__, 3).'/package.json'), true)['dependencies'];
    $manifest = json_decode($this->fresh->contents('package.json'), true);
    $manifest[$section]['typescript'] = '^5.9';
    $this->fresh->put('package.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

    $this->artisan('keystone:install')->assertSuccessful();

    $manifest = json_decode($this->fresh->contents('package.json'), true);
    $packages = array_merge($manifest['dependencies'] ?? [], $manifest['devDependencies']);

    expect($packages)->toHaveKey('typescript', '^5.9')
        ->toHaveKey('vue', $adapterPackages['vue'])
        ->and(array_intersect_key($manifest['dependencies'] ?? [], $manifest['devDependencies']))->toBe([]);
})->with([
    'in dependencies' => ['dependencies'],
    'in devDependencies' => ['devDependencies'],
]);

it('adds the packages and leaves every other line as the skeleton has it', function () {
    $adapterPackages = json_decode(file_get_contents(dirname(__DIR__, 3).'/package.json'), true)['dependencies'];
    $skeleton = json_decode($this->fresh->contents('package.json'), true)['devDependencies'];
    $before = explode("\n", $this->fresh->contents('package.json'));
    expect($before)->toContain('    "optionalDependencies": {');

    $this->artisan('keystone:install')->assertSuccessful();

    $after = explode("\n", $this->fresh->contents('package.json'));
    $removed = array_values(array_diff($before, $after));
    $added = array_values(array_diff($after, $before));

    expect($removed)->toHaveCount(1)
        ->and($added)->toContain($removed[0].',')
        ->and($added)->toHaveCount(count(array_diff_key($adapterPackages, $skeleton)) + 1);
});

it('prints the packages when devDependencies is laid out differently, and fails', function () {
    $compact = json_encode(json_decode($this->fresh->contents('package.json')), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    $this->fresh->put('package.json', $compact);

    $this->artisan('keystone:install')
        ->expectsOutputToContain('"typescript": "^6"')
        ->assertFailed();

    expect($this->fresh->contents('package.json'))->toBe($compact);
});

it("adds each package before the first devDependency that sorts after it, leaving the app's order alone", function () {
    $manifest = json_decode($this->fresh->contents('package.json'), true);
    $manifest['devDependencies'] = ['vite' => '^8.3', 'axios' => '^1.0'];
    $this->fresh->put('package.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

    $this->artisan('keystone:install')->assertSuccessful();

    $keys = array_keys(json_decode($this->fresh->contents('package.json'), true)['devDependencies']);

    expect(array_search('@inertiajs/vue3', $keys))->toBeLessThan(array_search('vite', $keys))
        ->and(array_search('vite', $keys))->toBeLessThan(array_search('axios', $keys))
        ->and(array_search('axios', $keys))->toBeLessThan(array_search('vue', $keys));
});
