<?php

use ClaudioDekker\Keystone\InertiaVue\Install\Stubs;
use ClaudioDekker\Keystone\InertiaVue\Tests\InstallsIntoFreshApp;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakePinType;
use Composer\InstalledVersions;

uses(InstallsIntoFreshApp::class);

it('changes nothing on a second run', function () {
    $this->artisan('keystone:install')->assertSuccessful();
    $installed = $this->fresh->fingerprint();

    $this->artisan('keystone:install')->assertSuccessful();

    expect($this->fresh->fingerprint())->toBe($installed);
});

it('finishes a run that stopped part way', function () {
    $this->artisan('keystone:install')->assertSuccessful();
    $installed = $this->fresh->fingerprint();
    unlink($this->fresh->path('routes/keystone.php'));
    $this->fresh->put('config/auth.php', file_get_contents(InstalledVersions::getInstallPath('laravel/laravel').'/config/auth.php'));

    $this->artisan('keystone:install')->assertSuccessful();

    expect($this->fresh->fingerprint())->toBe($installed);
});

it('adds the partial of a credential type installed since', function () {
    $this->app->instance(Stubs::class, new Stubs(dirname(__DIR__, 2).'/Fixtures/stubs'));
    $this->artisan('keystone:install')->assertSuccessful();
    expect($this->fresh->has('resources/js/partials/Totp.vue'))->toBeFalse();

    Keystone::credentialType(new class extends FakePinType
    {
        public function name(): string
        {
            return 'totp';
        }
    });

    $this->artisan('keystone:install')->assertSuccessful();

    expect($this->fresh->has('resources/js/partials/Totp.vue'))->toBeTrue();
});

it('maps the AppTests of a Keystone package installed since', function () {
    $this->fresh->uninstall('keystone-password');
    $this->artisan('keystone:install')->assertSuccessful();
    expect($this->fresh->contents('composer.json'))->not->toContain('keystone-password');

    $this->fresh->install('keystone-password');

    $this->artisan('keystone:install')->assertSuccessful();

    expect(json_decode($this->fresh->contents('composer.json'), true)['autoload-dev']['psr-4'])
        ->toHaveKey('ClaudioDekker\\Keystone\\Password\\AppTests\\', 'vendor/claudiodekker/keystone-password/app-tests/');
});
