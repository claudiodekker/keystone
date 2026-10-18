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

describe('the user model', function () {
    it('implements KeystoneUser, with its import', function () {
        $this->artisan('keystone:install')->assertSuccessful();

        $model = $this->fresh->contents('app/Models/User.php');

        expect($model)->toContain("\nclass User extends Authenticatable implements KeystoneUser\n")
            ->toContain("\nuse ClaudioDekker\\Keystone\\Auth\\KeystoneUser;\nuse Database\\Factories\\UserFactory;\n");
    });

    it('joins the interfaces the model already implements', function () {
        $this->fresh->put('app/Models/User.php', implode("\n", [
            '<?php',
            '',
            'namespace App\\Models;',
            '',
            'use Illuminate\\Contracts\\Auth\\MustVerifyEmail;',
            'use Illuminate\\Foundation\\Auth\\User as Authenticatable;',
            '',
            'class User extends Authenticatable implements MustVerifyEmail',
            '{',
            '}',
            '',
        ]));

        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('app/Models/User.php'))
            ->toContain("\nclass User extends Authenticatable implements MustVerifyEmail, KeystoneUser\n")
            ->toContain("\nuse ClaudioDekker\\Keystone\\Auth\\KeystoneUser;\nuse Illuminate\\Contracts\\Auth\\MustVerifyEmail;\n");
    });

    it('is found through the PSR-4 roots of the app, wherever the provider points', function () {
        $manifest = json_decode($this->fresh->contents('composer.json'), true);
        $manifest['autoload']['psr-4']['Domain\\Accounts\\'] = 'src/Accounts/';
        $this->fresh->put('composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->fresh->put('src/Accounts/Member.php', implode("\n", [
            '<?php',
            '',
            'namespace Domain\\Accounts;',
            '',
            'use Illuminate\\Foundation\\Auth\\User;',
            '',
            'class Member extends User',
            '{',
            '}',
            '',
        ]));

        config(['auth.providers.users.model' => 'Domain\\Accounts\\Member']);

        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('src/Accounts/Member.php'))->toContain("\nclass Member extends User implements KeystoneUser\n")
            ->and($this->fresh->contents('app/Models/User.php'))->not->toContain('KeystoneUser');
    });

    it('prints the edit, as for an unknown file, when no PSR-4 root of the app holds the model', function () {
        config(['auth.providers.users.model' => 'Vendor\\Accounts\\Member']);

        $this->artisan('keystone:install')
            ->expectsOutputToContain('unknown file')
            ->expectsOutputToContain('Add implements ClaudioDekker\\Keystone\\Auth\\KeystoneUser to Vendor\\Accounts\\Member.')
            ->assertFailed();
    });

    it('prints the edit, as for an unknown file, when the default guard has no user model to find', function (array $config) {
        config($config);

        $this->artisan('keystone:install')
            ->expectsOutputToContain('unknown file')
            ->expectsOutputToContain('Add implements ClaudioDekker\\Keystone\\Auth\\KeystoneUser to your user model.')
            ->assertFailed();

        expect($this->fresh->contents('app/Models/User.php'))->not->toContain('KeystoneUser');
    })->with([
        'no default guard' => [['auth.defaults.guard' => null]],
        'a guard without a provider' => [['auth.guards.web.provider' => null]],
        'a provider without a model' => [['auth.providers.users' => ['driver' => 'database', 'table' => 'users']]],
    ]);

    it('prints the edit when the class line is not the skeleton\'s, and fails', function () {
        $this->fresh->put('app/Models/User.php', "<?php\n\nnamespace App\\Models;\n\nclass User\n{\n}\n");

        $this->artisan('keystone:install')
            ->expectsOutputToContain('Add implements ClaudioDekker\\Keystone\\Auth\\KeystoneUser to App\\Models\\User.')
            ->assertFailed();

        expect($this->fresh->contents('app/Models/User.php'))->not->toContain('KeystoneUser');
    });
});
