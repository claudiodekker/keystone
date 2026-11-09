<?php

// Fault injection through the fake type: only core's fake advances a secret on demand, or races another write, so no AppTest can.

use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Auth;

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
