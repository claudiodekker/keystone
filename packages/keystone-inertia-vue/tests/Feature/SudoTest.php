<?php

use ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions\SudoAssertions;
use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Password\AppTests\Support\PasswordTypeSupport;
use ClaudioDekker\Keystone\Totp\AppTests\Support\TotpTypeSupport;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

pest()->extend(StubsTestCase::class)->use(SudoAssertions::class);

beforeEach(function () {
    $this->withoutMandates();
    config(['keystone.methods' => ['password', 'totp']]);
    Route::middleware(['web', 'sudo'])->get('gated', fn () => 'the gated page');
});

describe('the sudo page', function () {
    it('renders the first step with the page value\'s fields', function () {
        $this->signInAccount(new PasswordTypeSupport);
        $this->delete(route('sudo.end'));
        $this->get('gated');

        $response = $this->get(route('sudo'));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/Sudo')
            ->where('types', [['type' => 'password', 'shape' => 'form']])
            ->where('preselect', 'password')
            ->where('surface', 'sign-in'));
        $this->assertSudoPage($response, ['password']);
    });

    it('renders the challenge step once the first factor passed', function () {
        $this->createChallengedAccount(new TotpTypeSupport);
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'totp']), (new TotpTypeSupport)->validProof(Surface::CHALLENGE));
        $this->delete(route('sudo.end'));
        $this->get('gated');
        $this->post(route('sudo.submit', ['type' => 'password']), (new PasswordTypeSupport)->validProof(Surface::SIGN_IN));

        $response = $this->get(route('sudo'));

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/Sudo')
            ->where('types', [['type' => 'totp', 'shape' => 'form']])
            ->where('preselect', 'totp')
            ->where('surface', 'challenge'));
        $this->assertSudoPage($response, ['totp']);
    });

    it('encrypts the sudo page in the browser\'s history', function () {
        $this->signInAccount(new PasswordTypeSupport);
        $this->delete(route('sudo.end'));
        $this->get('gated');

        $response = $this->get(route('sudo'));

        expect($response->inertiaPage())->toHaveKey('encryptHistory', true);
    });
});

describe('the replay', function () {
    it('sends the gate\'s refusal to the sudo page', function () {
        $this->signInAccount(new PasswordTypeSupport);
        $this->delete(route('sudo.end'));

        $response = $this->get('gated');

        $this->assertSudoRequired($response);
    });

    it('sends a refused answer back to the sudo page with the message on the type', function () {
        $this->signInAccount(new PasswordTypeSupport);
        $this->delete(route('sudo.end'));
        $this->get('gated');

        $response = $this->post(route('sudo.submit', ['type' => 'password']), (new PasswordTypeSupport)->rejectedProof(Surface::SIGN_IN));

        $this->assertSudoRefused($response, 'password');
    });

    it('sends a passed first factor on to the challenge step', function () {
        $this->createChallengedAccount(new TotpTypeSupport);
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'totp']), (new TotpTypeSupport)->validProof(Surface::CHALLENGE));
        $this->delete(route('sudo.end'));
        $this->get('gated');

        $response = $this->post(route('sudo.submit', ['type' => 'password']), (new PasswordTypeSupport)->validProof(Surface::SIGN_IN));

        $this->assertSudoChallengeOwed($response);
    });

    it('sends the user on to the page they were refused once sudo is granted', function () {
        $this->signInAccount(new PasswordTypeSupport);
        $this->delete(route('sudo.end'));
        $this->get('gated');

        $response = $this->post(route('sudo.submit', ['type' => 'password']), (new PasswordTypeSupport)->validProof(Surface::SIGN_IN));

        $this->assertSudoGranted($response, '/gated');
        $this->get('gated')->assertOk();
    });
});

describe('ending sudo', function () {
    it('sends the user whose sudo ended to the security page', function () {
        $this->signInAccount(new PasswordTypeSupport);

        $response = $this->delete(route('sudo.end'));

        $this->assertSudoEnded($response);
        expect(session('keystone.status'))->toBe('sudo-revoked');
    });

    it('sends the user whose sudo ended to the security page whatever page they ended it from', function () {
        $this->signInAccount(new PasswordTypeSupport);

        $response = $this->from('/gated')->delete(route('sudo.end'));

        $response->assertRedirect('/settings/security');
    });

    it('sends a guest to the sign-in page', function () {
        $this->assertGuestSentAway($this->delete(route('sudo.end')));
    });
});
