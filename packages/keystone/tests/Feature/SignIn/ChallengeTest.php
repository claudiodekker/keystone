<?php

// Fault injection through the fake types: no installed type serves both factors or hands back a credential it wasn't given, so no AppTest can.

use ClaudioDekker\Keystone\Accounts\CredentialEpoch;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\Session\Stage;
use ClaudioDekker\Keystone\SignIn\Challenge;
use ClaudioDekker\Keystone\SignIn\Refused;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;

it("signs nobody in with an answer of the first factor's type", function () {
    $ada = User::factory()->create();
    UserCredential::factory()->for($ada, 'user')->create(['type' => 'challenge-only', 'secret' => 'second horse']);
    $type = app(CredentialTypes::class)->find('challenge-only', Surface::CHALLENGE);
    app(SessionState::class)->hold($ada, $type, Stage::CHALLENGE);

    $outcome = $this->app->make(Challenge::class)->answer($type, ['secret' => 'second horse']);

    expect($outcome)->toBeInstanceOf(Refused::class)
        ->and(Keystone::guard()->check())->toBeFalse();
});

it("signs nobody in with an answer that proves another account's credential", function () {
    $ada = User::factory()->create();
    $grace = User::factory()->create();
    UserCredential::factory()->for($ada, 'user')->create(['type' => 'challenge-only', 'secret' => 'second horse']);
    $graces = UserCredential::factory()->for($grace, 'user')->create(['type' => 'challenge-only', 'secret' => 'grace horse']);
    $type = app(CredentialTypes::class)->find('challenge-only', Surface::CHALLENGE);
    $type->forgeProofOf($graces);
    app(SessionState::class)->hold($ada, app(CredentialTypes::class)->find('form', Surface::SIGN_IN), Stage::CHALLENGE);

    $outcome = $this->app->make(Challenge::class)->answer($type, ['secret' => 'grace horse']);

    expect($outcome)->toBeInstanceOf(Refused::class)
        ->and(Keystone::guard()->check())->toBeFalse();
});

it("writes no advanced secret for a pending sign-in whose account's credential epoch moved while the type was verifying", function () {
    $ada = User::factory()->create();
    $credential = UserCredential::factory()->for($ada, 'user')->create(['type' => 'challenge-only', 'secret' => 'second horse']);
    $type = app(CredentialTypes::class)->find('challenge-only', Surface::CHALLENGE);
    $type->duringNextVerify(fn () => CredentialEpoch::move($ada->getKey()));
    $type->advanceNextProof('advanced horse');
    app(SessionState::class)->hold($ada, app(CredentialTypes::class)->find('form', Surface::SIGN_IN), Stage::CHALLENGE);

    $outcome = $this->app->make(Challenge::class)->answer($type, ['secret' => 'second horse']);

    expect($outcome)->toBeInstanceOf(Refused::class)
        ->and(Keystone::guard()->check())->toBeFalse()
        ->and($credential->fresh()->secret)->toBe('second horse');
});
