<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\ChallengeAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Totp\AppTests\Support\TotpTypeSupport;
use Inertia\Testing\AssertableInertia;

pest()->extend(StubsTestCase::class)->use(ChallengeAssertions::class);

beforeEach(function () {
    $this->createChallengedAccount(new TotpTypeSupport);
    $this->passFirstFactor();
});

it('renders the challenge page with the page value\'s fields', function () {
    $response = $this->get(route('login.challenge'));

    $response->assertInertia(fn (AssertableInertia $page) => $page
        ->component('auth/Challenge')
        ->where('types', [['type' => 'totp', 'shape' => 'form']])
        ->where('preselect', 'totp'));
});

it('encrypts the challenge page in the browser\'s history', function () {
    $response = $this->get(route('login.challenge'));

    expect($response->inertiaPage())->toHaveKey('encryptHistory', true);
});

it('sends a refused answer back to the challenge page with the message on the type', function () {
    $response = $this->post(route('login.challenge.submit', ['type' => 'totp']), (new TotpTypeSupport)->rejectedProof(Surface::CHALLENGE));

    $this->assertChallengeRefused($response, 'totp');
});

it('sends the signed-in user on to the intended URL', function () {
    $response = $this->post(route('login.challenge.submit', ['type' => 'totp']), (new TotpTypeSupport)->validProof(Surface::CHALLENGE));

    $this->assertChallengePassed($response, '/');
});

it('sends a cancelled sign-in to the sign-in page', function () {
    $response = $this->delete(route('login.challenge.cancel'));

    $this->assertChallengeCancelled($response);
});
