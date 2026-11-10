<?php

// Ceremony slots: no installed type starts a ceremony yet, so only core's fake can.

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\Session\Stage;
use ClaudioDekker\Keystone\SignIn\Challenge;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakeCeremonyType;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;

it("gives the type's verify what its ceremony's start kept", function () {
    $ada = User::factory()->create();
    UserCredential::factory()->for($ada, 'user')->create(['type' => 'challenge-only', 'secret' => 'second horse']);
    $type = new FakeCeremonyType;
    $session = app(SessionState::class);
    $session->hold($ada, app(CredentialTypes::class)->find('form', Surface::SIGN_IN), Stage::CHALLENGE);
    $session->startCeremony($type, Surface::CHALLENGE);

    $this->app->make(Challenge::class)->answer($type, ['secret' => 'second horse']);

    expect($type->verifications()[0]->ceremony)->toBe(['challenge' => 'challenge-only ceremony 1', 'surface' => 'challenge']);
});

it("keeps each type's ceremony on each surface in a slot of its own", function () {
    $type = new FakeCeremonyType;
    $other = new class extends FakeCeremonyType
    {
        public function name(): string
        {
            return 'other-ceremony';
        }
    };

    $session = app(SessionState::class);
    $session->startCeremony($type, Surface::CHALLENGE);
    $session->startCeremony($other, Surface::CHALLENGE);
    $session->startCeremony($type, Surface::ENROLLMENT);

    $ceremony = $session->ceremony($type, Surface::CHALLENGE);

    expect($ceremony)->toBe(['challenge' => 'challenge-only ceremony 1', 'surface' => 'challenge']);
});

it("replaces the type's ceremony on the surface when it starts again", function () {
    $type = new FakeCeremonyType;
    $session = app(SessionState::class);
    $session->startCeremony($type, Surface::CHALLENGE);
    $session->startCeremony($type, Surface::CHALLENGE);

    $ceremony = $session->ceremony($type, Surface::CHALLENGE);

    expect($ceremony)->toBe(['challenge' => 'challenge-only ceremony 2', 'surface' => 'challenge']);
});

it('ends every ceremony at each change of auth level', function (Closure $change) {
    $ada = User::factory()->create();
    $type = new FakeCeremonyType;
    $other = new class extends FakeCeremonyType
    {
        public function name(): string
        {
            return 'other-ceremony';
        }
    };

    $session = app(SessionState::class);
    $session->hold($ada, app(CredentialTypes::class)->find('form', Surface::SIGN_IN), Stage::CHALLENGE);
    $session->startCeremony($type, Surface::CHALLENGE);
    $session->startCeremony($other, Surface::ENROLLMENT);

    $change($session, $ada);

    expect($session->ceremony($type, Surface::CHALLENGE))->toBeNull()
        ->and($session->ceremony($other, Surface::ENROLLMENT))->toBeNull();
})->with([
    'a hold' => [fn (SessionState $session, User $ada) => $session->hold($ada, app(CredentialTypes::class)->find('form', Surface::SIGN_IN), Stage::CHALLENGE)],
    'a sign-in' => [fn (SessionState $session, User $ada) => $session->signIn($ada)],
    'a cancel' => [fn (SessionState $session) => $session->dropPendingSignIn()],
]);
