<?php

// Fault injection through the fake type: no installed type hands back a credential it wasn't given, so no AppTest can.

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\SignIn\Refused;
use ClaudioDekker\Keystone\SignIn\SignIn;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;

it("signs nobody in with a proof of another account's credential", function () {
    $ada = User::factory()->create();
    $grace = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    $graces = UserCredential::factory()->for($grace, 'user')->create(['type' => 'form', 'secret' => 'grace horse']);
    $type = Keystone::credentialTypes()->find('form', Surface::SIGN_IN);
    $type->forgeProofOf($graces);

    $outcome = $this->app->make(SignIn::class)->attempt($type, [
        'identifier' => 'ada@example.com',
        'secret' => 'grace horse',
    ]);

    expect($outcome)->toBeInstanceOf(Refused::class)
        ->and(Keystone::guard()->check())->toBeFalse();
});

it("signs nobody in with a proof of the account's credential of another type", function () {
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    $other = UserCredential::factory()->for($ada, 'user')->create(['type' => 'other', 'secret' => 'other horse']);
    $type = Keystone::credentialTypes()->find('form', Surface::SIGN_IN);
    $type->forgeProofOf($other);

    $outcome = $this->app->make(SignIn::class)->attempt($type, [
        'identifier' => 'ada@example.com',
        'secret' => 'other horse',
    ]);

    expect($outcome)->toBeInstanceOf(Refused::class)
        ->and(Keystone::guard()->check())->toBeFalse();
});

it('signs nobody in with a proof when the identifier names no account', function () {
    $grace = User::factory()->create();
    $graces = UserCredential::factory()->for($grace, 'user')->create(['type' => 'form', 'secret' => 'grace horse']);
    $type = Keystone::credentialTypes()->find('form', Surface::SIGN_IN);
    $type->forgeProofOf($graces);

    $outcome = $this->app->make(SignIn::class)->attempt($type, [
        'identifier' => 'nobody@example.com',
        'secret' => 'grace horse',
    ]);

    expect($outcome)->toBeInstanceOf(Refused::class)
        ->and(Keystone::guard()->check())->toBeFalse();
});

it('still calls the type, with no credentials, when the identifier names no account', function () {
    $type = Keystone::credentialTypes()->find('form', Surface::SIGN_IN);

    $this->app->make(SignIn::class)->attempt($type, [
        'identifier' => 'nobody@example.com',
        'secret' => 'any horse',
    ]);

    expect($type->verifications())->toHaveCount(1)
        ->and($type->verifications()[0]->credentials)->toBe([])
        ->and($type->verifications()[0]->input)->toBe(['secret' => 'any horse']);
});

it('makes the type compare against a decoy secret when the identifier names no account', function () {
    $type = Keystone::credentialTypes()->find('form', Surface::SIGN_IN);

    $this->app->make(SignIn::class)->attempt($type, [
        'identifier' => 'nobody@example.com',
        'secret' => 'any horse',
    ]);

    expect($type->comparisons())->toBe(1);
});
