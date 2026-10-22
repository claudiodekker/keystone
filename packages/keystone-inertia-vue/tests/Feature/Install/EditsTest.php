<?php

use ClaudioDekker\Keystone\InertiaVue\Install\Stubs;
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
    it('is replaced with one that implements KeystoneUser', function () {
        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('app/Models/User.php'))
            ->toBe(file_get_contents(app(Stubs::class)->path('app/Models/User.php')))
            ->toContain('class User extends Authenticatable implements KeystoneUser');
    });

    it('is replaced when the app changed it', function () {
        $this->fresh->put('app/Models/User.php', "<?php\n\nnamespace App\\Models;\n\nclass User\n{\n}\n");

        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('app/Models/User.php'))->toBe(file_get_contents(app(Stubs::class)->path('app/Models/User.php')));
    });

    it('is kept once it implements KeystoneUser', function () {
        $this->artisan('keystone:install')->assertSuccessful();
        $changed = str_replace('use HasFactory, Notifiable;', 'use HasFactory, Notifiable, SoftDeletes;', $this->fresh->contents('app/Models/User.php'));
        $this->fresh->put('app/Models/User.php', $changed);

        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('app/Models/User.php'))->toBe($changed);
    });
});

describe('config/auth.php', function () {
    it("sets the default guard's driver to keystone", function () {
        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('config/auth.php'))->toContain("'web' => [\n            'driver' => 'keystone',\n            'provider' => 'users',\n");
    });

    it('prints the edit when the guard uses a driver of its own, and fails', function () {
        $this->fresh->put('config/auth.php', str_replace("'driver' => 'session'", "'driver' => 'token'", $this->fresh->contents('config/auth.php')));

        $this->artisan('keystone:install')
            ->expectsOutputToContain("Set auth.guards.web.driver to 'keystone' in config/auth.php.")
            ->assertFailed();

        expect($this->fresh->contents('config/auth.php'))->toContain("'driver' => 'token'")
            ->not->toContain('keystone');
    });

    it('prints the edit when there is no default guard, and fails', function () {
        config(['auth.defaults.guard' => null]);

        $this->artisan('keystone:install')
            ->expectsOutputToContain("Set the driver of your default guard to 'keystone' in config/auth.php.")
            ->assertFailed();

        expect($this->fresh->contents('config/auth.php'))->not->toContain("'driver' => 'keystone'");
    });

    it('prints the edit, with the guard name quoted, when the guard name has regex characters', function () {
        config(['auth.defaults.guard' => 'web.*']);

        $this->artisan('keystone:install')
            ->expectsOutputToContain("Set auth.guards.web.*.driver to 'keystone' in config/auth.php.")
            ->assertFailed();

        expect($this->fresh->contents('config/auth.php'))->not->toContain("'driver' => 'keystone'");
    });
});

describe('the session cookie', function () {
    it('is named __Host-<app>-session and marked secure in .env.example, after the session block', function () {
        config(['app.name' => 'Acme Shop']);

        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('.env.example'))
            ->toContain("SESSION_DOMAIN=null\nSESSION_COOKIE=__Host-acme-shop-session\nSESSION_SECURE_COOKIE=true\n")
            ->and($this->fresh->has('.env'))->toBeFalse();
    });

    it('is set in .env too when the app has one', function () {
        $this->fresh->put('.env', $this->fresh->contents('.env.example'));

        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('.env'))->toContain("SESSION_DOMAIN=null\nSESSION_COOKIE=__Host-laravel-session\nSESSION_SECURE_COOKIE=true\n");
    });

    it('prints the edit when the app names its cookie already, and fails', function () {
        $this->fresh->put('.env.example', $this->fresh->contents('.env.example')."SESSION_COOKIE=acme\n");

        $this->artisan('keystone:install')
            ->expectsOutputToContain('Set SESSION_COOKIE=__Host-laravel-session and SESSION_SECURE_COOKIE=true in .env.example, with SESSION_DOMAIN=null.')
            ->assertFailed();

        expect($this->fresh->contents('.env.example'))->toContain("SESSION_COOKIE=acme\n")
            ->not->toContain('SESSION_SECURE_COOKIE');
    });

    it('prints the edit when the session domain is set, because a __Host- cookie takes no domain, and fails', function () {
        $this->fresh->put('.env.example', str_replace('SESSION_DOMAIN=null', 'SESSION_DOMAIN=acme.test', $this->fresh->contents('.env.example')));

        $this->artisan('keystone:install')
            ->expectsOutputToContain('with SESSION_DOMAIN=null.')
            ->assertFailed();

        expect($this->fresh->contents('.env.example'))->not->toContain('SESSION_COOKIE');
    });
});
