<?php

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneServiceProvider;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Auth;

it('starts each app from no registered credential types', function () {
    $provider = new KeystoneServiceProvider($this->app);

    $provider->register();

    expect(Keystone::credentialTypes()->shapes(Surface::SIGN_IN))->toBe([]);
});

it('reads the user model from the keystone guard', function () {
    expect(Keystone::userModel())->toBe(User::class);
});

it('names the fix when the default guard has another driver', function () {
    config(['auth.guards.web.driver' => 'session']);
    Auth::forgetGuards();

    expect(fn () => Keystone::userModel())->toThrow(LogicException::class, 'Keystone needs the keystone driver on the default auth guard.');
});

it('names the fix when the keystone guard has no user provider', function () {
    config(['auth.guards.web.provider' => 'missing']);
    Auth::forgetGuards();

    expect(fn () => Keystone::userModel())->toThrow(LogicException::class, 'Keystone needs a user provider on the [web] guard.');
});

it('names the fix when the user provider is not eloquent', function () {
    config(['auth.providers.users.driver' => 'database', 'auth.providers.users.table' => 'members']);
    Auth::forgetGuards();

    expect(fn () => Keystone::userModel())->toThrow(LogicException::class, 'Keystone needs the eloquent user provider on the keystone guard.');
});

it('names the fix when the user model does not implement KeystoneUser', function () {
    config(['auth.providers.users.model' => Authenticatable::class]);
    Auth::forgetGuards();

    expect(fn () => Keystone::userModel())->toThrow(LogicException::class, 'Keystone needs the user model ['.Authenticatable::class.'] to implement KeystoneUser.');
});
