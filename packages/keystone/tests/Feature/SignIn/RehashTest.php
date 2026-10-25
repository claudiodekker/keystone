<?php

// Fault injection through the fake type: only core's fake hands back a rehash on demand, or one that throws, so no AppTest can.

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;

it('signs in with the secret the type rehashed the credential to', function () {
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserCredential::factory()->for($ada, 'user')->create(['type' => 'form', 'secret' => 'correct horse']);
    app(CredentialTypes::class)->find('form', Surface::SIGN_IN)->rehashNextProof(fn () => 'new horse');

    $first = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'correct horse']);

    Auth::forgetGuards();
    $this->flushSession();
    $withNew = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'new horse']);

    Auth::forgetGuards();
    $this->flushSession();
    $withOld = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'correct horse']);

    $first->assertRedirect('/');
    $withNew->assertRedirect('/');
    $withOld->assertRedirectToRoute('login');
    $withOld->assertSessionHasErrors('identifier');
});

it('keeps a secret written while the type was verifying', function () {
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    $credential = UserCredential::factory()->for($ada, 'user')->create(['type' => 'form', 'secret' => 'correct horse']);
    $type = app(CredentialTypes::class)->find('form', Surface::SIGN_IN);
    $type->duringNextVerify(function () use ($credential) {
        $credential->secret = 'changed horse';
        $credential->save();
    });

    $type->rehashNextProof(fn () => 'new horse');

    $first = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'correct horse']);

    Auth::forgetGuards();
    $this->flushSession();
    $withChanged = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'changed horse']);

    Auth::forgetGuards();
    $this->flushSession();
    $withNew = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'new horse']);

    $first->assertRedirect('/');
    $withChanged->assertRedirect('/');
    $withNew->assertRedirectToRoute('login');
    $withNew->assertSessionHasErrors('identifier');
});

it('signs in and reports a rehash that throws, keeping the secret', function () {
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserCredential::factory()->for($ada, 'user')->create(['type' => 'form', 'secret' => 'correct horse']);
    app(CredentialTypes::class)->find('form', Surface::SIGN_IN)->rehashNextProof(fn () => throw new RuntimeException('The hasher is down.'));

    $first = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'correct horse']);

    Auth::forgetGuards();
    $this->flushSession();
    $again = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'correct horse']);

    $first->assertRedirect('/');
    $again->assertRedirect('/');
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The hasher is down.');
});

it('never runs the rehash of a proof that signs nobody in', function () {
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    $grace = User::factory()->create();
    $graces = UserCredential::factory()->for($grace, 'user')->create(['type' => 'form', 'secret' => 'grace horse']);
    $ran = false;
    $type = app(CredentialTypes::class)->find('form', Surface::SIGN_IN);
    $type->forgeProofOf($graces);
    $type->rehashNextProof(function () use (&$ran) {
        $ran = true;

        return 'new horse';
    });

    $response = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'grace horse']);

    $response->assertRedirectToRoute('login');
    expect($ran)->toBeFalse()
        ->and($graces->fresh()->secret)->toBe('grace horse');
});

it('leaves a credential the type proved but was never given alone', function () {
    $ada = User::factory()->create();
    UserEmail::factory()->for($ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    UserCredential::factory()->for($ada, 'user')->create(['type' => 'form', 'secret' => 'correct horse']);
    $enrolled = null;
    $type = app(CredentialTypes::class)->find('form', Surface::SIGN_IN);
    $type->duringNextVerify(function () use ($ada, $type, &$enrolled) {
        $enrolled = UserCredential::factory()->for($ada, 'user')->create(['type' => 'form', 'secret' => 'enrolled horse']);
        $type->forgeProofOf($enrolled);
    });

    $type->rehashNextProof(fn () => 'new horse');

    $response = $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'correct horse']);

    $response->assertRedirect('/');
    expect($enrolled->fresh()->secret)->toBe('enrolled horse');
    Exceptions::assertNothingReported();
});
