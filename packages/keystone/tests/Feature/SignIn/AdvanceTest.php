<?php

// Fault injection through the fake type: only core's fake advances a secret on demand, or races another write, so no AppTest can.

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    config(['keystone.require_recovery_codes' => false, 'keystone.require_second_factor' => false]);
});

it('signs in with the secret the proof advanced the credential to', function () {
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserCredential::factory()->for($ada, 'user')->create(['type' => 'form', 'secret' => 'correct horse']);
    app(CredentialTypes::class)->find('form', Surface::SIGN_IN)->advanceNextProof('advanced horse');

    $first = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'correct horse']);

    Auth::forgetGuards();
    $this->flushSession();
    $withAdvanced = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'advanced horse']);

    Auth::forgetGuards();
    $this->flushSession();
    $withOld = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'correct horse']);

    $first->assertRedirect('/');
    $withAdvanced->assertRedirect('/');
    $withOld->assertRedirectToRoute('login');
    $withOld->assertSessionHasErrors('identifier');
});

it('refuses a proof whose credential another write advanced while the type was verifying, keeping that write', function () {
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    $credential = UserCredential::factory()->for($ada, 'user')->create(['type' => 'form', 'secret' => 'correct horse']);
    $type = app(CredentialTypes::class)->find('form', Surface::SIGN_IN);
    $type->duringNextVerify(function () use ($credential) {
        $credential->secret = 'raced horse';
        $credential->save();
    });

    $type->advanceNextProof('advanced horse');

    $raced = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'correct horse']);

    Auth::forgetGuards();
    $this->flushSession();
    $withRaced = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'raced horse']);

    $raced->assertRedirectToRoute('login');
    $withRaced->assertRedirect('/');
});

it('keeps the attempt of a proof refused for a raced credential counted', function () {
    config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    $credential = UserCredential::factory()->for($ada, 'user')->create(['type' => 'form', 'secret' => 'correct horse']);
    $type = app(CredentialTypes::class)->find('form', Surface::SIGN_IN);
    $type->duringNextVerify(function () use ($credential) {
        $credential->secret = 'raced horse';
        $credential->save();
    });

    $type->advanceNextProof('advanced horse');

    $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'correct horse']);

    $afterRace = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'raced horse']);

    $afterRace->assertTooManyRequests();
});

it('refuses a proof that advances a credential the type was never given, leaving it alone', function () {
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserCredential::factory()->for($ada, 'user')->create(['type' => 'form', 'secret' => 'correct horse']);
    $enrolled = null;
    $type = app(CredentialTypes::class)->find('form', Surface::SIGN_IN);
    $type->duringNextVerify(function () use ($ada, $type, &$enrolled) {
        $enrolled = UserCredential::factory()->for($ada, 'user')->create(['type' => 'form', 'secret' => 'enrolled horse']);
        $type->forgeProofOf($enrolled);
    });

    $type->advanceNextProof('advanced horse');

    $response = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'correct horse']);

    $response->assertRedirectToRoute('login');
    $response->assertSessionHasErrors('identifier');
    expect($enrolled->fresh()->secret)->toBe('enrolled horse');
});
