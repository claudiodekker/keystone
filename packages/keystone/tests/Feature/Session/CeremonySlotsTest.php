<?php

// Ceremony slots: driven through core's fake, since no installed type starts a ceremony.

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

it('ends a ceremony 10 minutes after its start', function () {
    $this->freezeSecond();
    $type = new FakeCeremonyType;
    $session = app(SessionState::class);
    $session->startCeremony($type, Surface::CHALLENGE);

    $this->travel(10 * 60 - 1)->seconds();
    $lastSecond = $session->ceremony($type, Surface::CHALLENGE);
    $this->travel(1)->seconds();
    $ended = $session->ceremony($type, Surface::CHALLENGE);

    expect($lastSecond)->not->toBeNull()
        ->and($ended)->toBeNull();
});

it('ends a ceremony with the pending sign-in it was started under', function () {
    $this->freezeSecond();
    $ada = User::factory()->create();
    $type = new FakeCeremonyType;
    $session = app(SessionState::class);
    $session->hold($ada, app(CredentialTypes::class)->find('form', Surface::SIGN_IN), Stage::CHALLENGE);
    $this->travel(10 * 60)->seconds();
    $session->startCeremony($type, Surface::CHALLENGE);

    $this->travel(5 * 60 - 1)->seconds();
    $lastSecond = $session->ceremony($type, Surface::CHALLENGE);
    $this->travel(1)->seconds();
    $ended = $session->ceremony($type, Surface::CHALLENGE);

    expect($lastSecond)->not->toBeNull()
        ->and($ended)->toBeNull();
});

it('keeps a ceremony encrypted in the session', function () {
    $type = new FakeCeremonyType;
    $session = app(SessionState::class);

    $session->startCeremony($type, Surface::CHALLENGE);

    expect(serialize(session()->all()))->not->toContain('challenge-only ceremony 1')
        ->and($session->ceremony($type, Surface::CHALLENGE))->toBe(['challenge' => 'challenge-only ceremony 1', 'surface' => 'challenge']);
});

it('reads a ceremony kept under an app key since rotated away as none', function () {
    $type = new FakeCeremonyType;
    app(SessionState::class)->startCeremony($type, Surface::CHALLENGE);
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    $this->app->forgetInstance('encrypter');

    $ceremony = app(SessionState::class)->ceremony($type, Surface::CHALLENGE);

    expect($ceremony)->toBeNull();
});

it("ends the type's earlier ceremony on the surface when a start fails", function () {
    $type = new FakeCeremonyType;
    $session = app(SessionState::class);
    $session->startCeremony($type, Surface::CHALLENGE);
    $type->failNextStart();

    $failure = rescue(fn () => $session->startCeremony($type, Surface::CHALLENGE), fn (Throwable $exception) => $exception, report: false);

    expect($failure)->toBeInstanceOf(RuntimeException::class)
        ->and($session->ceremony($type, Surface::CHALLENGE))->toBeNull();
});

it("draws the page from the type's live ceremony on each read, without starting it again", function () {
    $type = new FakeCeremonyType;
    $session = app(SessionState::class);
    $session->startCeremony($type, Surface::ENROLLMENT);

    $first = $session->ceremonyPage($type, Surface::ENROLLMENT, 'ada@example.com');
    $second = $session->ceremonyPage($type, Surface::ENROLLMENT, 'ada@example.com');

    expect($first)->toBe(['shown' => 'challenge-only ceremony 1 on enrollment', 'account' => 'ada@example.com'])
        ->and($second)->toBe($first);
});
