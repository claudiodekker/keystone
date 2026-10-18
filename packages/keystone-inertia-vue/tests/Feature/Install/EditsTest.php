<?php

use ClaudioDekker\Keystone\InertiaVue\Tests\InstallsIntoFreshApp;

uses(InstallsIntoFreshApp::class);

describe('routes/web.php', function () {
    it('requires routes/keystone.php', function () {
        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('routes/web.php'))->toEndWith("\n\nrequire __DIR__.'/keystone.php';\n");
    });

    it('prints the require when the file is missing, and fails', function () {
        unlink($this->fresh->path('routes/web.php'));

        $this->artisan('keystone:install')
            ->expectsOutputToContain('missing')
            ->expectsOutputToContain("require __DIR__.'/keystone.php';")
            ->assertFailed();
    });
});
