<?php

// Fault injection through the fake type: only core's fake runs another write while this answer's verify waits on it, so no AppTest can race storing a second factor.

use ClaudioDekker\Keystone\Accounts\AccountChange;
use ClaudioDekker\Keystone\Accounts\AccountChanges;
use ClaudioDekker\Keystone\Jobs\EndSessions;
use ClaudioDekker\Keystone\Methods\CredentialTypeRegistry;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Session\SessionState;
use ClaudioDekker\Keystone\Session\Stage;
use ClaudioDekker\Keystone\SignIn\Enrollment;
use ClaudioDekker\Keystone\SignIn\Held;
use ClaudioDekker\Keystone\SignIn\Refused;
use ClaudioDekker\Keystone\Tests\Fixtures\Methods\FakeEnrollmentType;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;

beforeEach(function () {
    $this->type = new FakeEnrollmentType;

    app(CredentialTypeRegistry::class)->register($this->type);
});

it('stores one second factor when another answer stored one first', function () {
    $ada = User::factory()->create();
    app(SessionState::class)->hold($ada, app(CredentialTypes::class)->find('form', Surface::SIGN_IN), Stage::ENROLLMENT);
    app(SessionState::class)->startCeremony($this->type, Surface::ENROLLMENT);

    $this->type->duringNextVerify(fn () => app(AccountChanges::class)->change($ada, fn (AccountChange $change) => $change->addCredential($this->type, 'first horse', Surface::ENROLLMENT)));

    $outcome = $this->app->make(Enrollment::class)->answer($this->type, ['secret' => 'second horse']);

    expect($outcome)->toBeInstanceOf(Refused::class)
        ->and(UserCredential::query()->where('user_id', $ada->getKey())->pluck('secret')->all())->toBe(['first horse']);
});

it("stores nothing once the pending sign-in left its account's credential epoch", function () {
    $ada = User::factory()->create();
    app(SessionState::class)->hold($ada, app(CredentialTypes::class)->find('form', Surface::SIGN_IN), Stage::ENROLLMENT);
    app(SessionState::class)->startCeremony($this->type, Surface::ENROLLMENT);

    $this->type->duringNextVerify(fn () => EndSessions::dispatchSync($ada, operator: 'margaret'));

    $outcome = $this->app->make(Enrollment::class)->answer($this->type, ['secret' => 'second horse']);

    expect($outcome)->toBeInstanceOf(Refused::class)
        ->and(UserCredential::query()->where('user_id', $ada->getKey())->exists())->toBeFalse();
});

it('stores the second factor once when the same answer is sent again', function () {
    $ada = User::factory()->create();
    app(SessionState::class)->hold($ada, app(CredentialTypes::class)->find('form', Surface::SIGN_IN), Stage::ENROLLMENT);
    app(SessionState::class)->startCeremony($this->type, Surface::ENROLLMENT);
    $first = $this->app->make(Enrollment::class)->answer($this->type, ['secret' => 'second horse']);

    $retry = $this->app->make(Enrollment::class)->answer($this->type, ['secret' => 'second horse']);

    expect($first)->toBeInstanceOf(Held::class)
        ->and($retry)->toBeInstanceOf(Refused::class)
        ->and(UserCredential::query()->where('user_id', $ada->getKey())->count())->toBe(1);
});
