<?php

use ClaudioDekker\Keystone\InertiaVue\Install\Stubs;
use ClaudioDekker\Keystone\InertiaVue\Tests\InstallsIntoFreshApp;
use ClaudioDekker\Keystone\Keystone;

uses(InstallsIntoFreshApp::class);

beforeEach(function () {
    $this->app->instance(Stubs::class, new Stubs(dirname(__DIR__, 2).'/Fixtures/stubs'));
});

it('copies a partial only for each installed credential type', function () {
    $this->artisan('keystone:install')->assertSuccessful();

    expect($this->fresh->has('resources/js/partials/Pin.vue'))->toBeTrue()
        ->and($this->fresh->has('resources/js/partials/shapes/Form.vue'))->toBeTrue()
        ->and($this->fresh->has('resources/js/partials/Totp.vue'))->toBeFalse();
});

it('installs without any credential type, copying no partial', function () {
    expect(Keystone::credentialTypes()->names())->not->toBeEmpty();
    Keystone::flushState();

    $this->artisan('keystone:install')->assertSuccessful();

    expect($this->fresh->has('routes/keystone.php'))->toBeTrue()
        ->and($this->fresh->has('resources/js/partials/Pin.vue'))->toBeFalse()
        ->and($this->fresh->has('resources/js/partials/Totp.vue'))->toBeFalse();
});
