<?php

use ClaudioDekker\Keystone\InertiaVue\Tests\InstallsIntoFreshApp;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

uses(InstallsIntoFreshApp::class);

it('runs composer dump-autoload, npm install and npm run build in the app, in that order', function () {
    $this->artisan('keystone:install')->assertSuccessful();

    Process::assertRanInOrder(['composer dump-autoload', 'npm install', 'npm run build']);
    Process::assertRanTimes(fn (PendingProcess $process) => $process->path === $this->fresh->path(), 3);
});

it('prints the commands still to run when one fails, and fails', function () {
    Process::fake(fn (PendingProcess $process) => $process->command === 'npm install' ? Process::result(exitCode: 1) : Process::result());

    $this->artisan('keystone:install')
        ->expectsOutputToContain('npm run build')
        ->assertFailed();

    Process::assertRan('composer dump-autoload');
    Process::assertNotRan('npm run build');
});
