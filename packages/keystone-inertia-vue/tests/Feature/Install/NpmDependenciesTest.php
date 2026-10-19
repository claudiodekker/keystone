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

it("sets the adapter's version over the one the app pins", function () {
    $adapterPackages = json_decode(file_get_contents(dirname(__DIR__, 3).'/package.json'), true)['dependencies'];
    $manifest = json_decode($this->fresh->contents('package.json'), true);
    $manifest['devDependencies']['typescript'] = '^5.9';
    $manifest['devDependencies']['vite'] = '^7.0.7';
    $this->fresh->put('package.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

    $this->artisan('keystone:install')->assertSuccessful();

    expect(json_decode($this->fresh->contents('package.json'), true)['devDependencies'])
        ->toHaveKey('typescript', '^6')
        ->toHaveKey('vite', $adapterPackages['vite']);
});

it("keeps the app's other packages and sections", function () {
    $adapterPackages = json_decode(file_get_contents(dirname(__DIR__, 3).'/package.json'), true)['dependencies'];
    $before = json_decode($this->fresh->contents('package.json'), true);

    $this->artisan('keystone:install')->assertSuccessful();

    $after = json_decode($this->fresh->contents('package.json'), true);

    expect(array_diff_key($after, ['devDependencies' => true]))->toBe(array_diff_key($before, ['devDependencies' => true]))
        ->and($after['devDependencies'])->toMatchArray(array_diff_key($before['devDependencies'], $adapterPackages));
});

it('prints the packages when package.json is not valid JSON, and fails', function () {
    $this->fresh->put('package.json', "{\n");

    $this->artisan('keystone:install')
        ->expectsOutputToContain('"typescript": "^6"')
        ->assertFailed();

    expect($this->fresh->contents('package.json'))->toBe("{\n");
});
