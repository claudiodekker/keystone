<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\SudoAssertions;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvent;

pest()->extend(AppTestCase::class)->use(AppTestCase::assertions(SudoAssertions::class));

beforeEach(function () {
    $this->withoutMandates();
    $this->gated = $this->gatedRoute();
});

it('records the sudo a sign-in brings on the account\'s trail', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);

    $this->assertAuthenticatedAs($account);
    $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.granted', 'user_id' => $account->getKey(), 'reason' => 'keystone.sign_in']);
});

describe('the gate', function () {
    it('lets a fresh sign-in through', function () {
        $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);

        $response = $this->get($this->gated);

        $this->assertSudoPassed($response);
        $this->assertAuthenticatedAs($account);
        expect(SecurityEvent::query()->where('type', 'sudo.failed')->count())->toBe(0);
    });

    it('refuses a session whose sudo ended', function () {
        $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
        $this->delete(route('sudo.end'));

        $response = $this->get($this->gated);

        $this->assertSudoRequired($response);
        $this->assertAuthenticatedAs($account);
    });

    it('refuses a session whose sudo ran out', function () {
        $this->freezeSecond();
        $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
        $this->outliveSudo();

        $response = $this->get($this->gated);

        $this->assertSudoRequired($response);
        $this->assertAuthenticatedAs($account);
    });

    it('answers a JSON request without sudo with a refusal', function () {
        $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
        $this->delete(route('sudo.end'));

        $response = $this->getJson($this->gated);

        $this->assertSudoForbidden($response);
    });

    it('refuses a remembered return', function () {
        if (config('keystone.remember.lifetime_seconds') === 0) {
            $this->markTestSkipped('The app turned remember-me off.');
        }

        $support = $this->supportsFor(Surface::SIGN_IN)[0];
        $account = $this->createAccount();
        $this->arrangeCredential($account, $support, Surface::SIGN_IN);
        $response = $this->submitSignIn($support, 'jane@example.com', [...$support->validProof(Surface::SIGN_IN), 'remember' => '1']);
        session()->invalidate();
        $this->fromRememberCookie($this->rememberCookieOf($response))->fromDevice($this->deviceCookieOf($response));

        $response = $this->get($this->gated);

        $this->assertSudoRequired($response);
        $this->assertAuthenticatedAs($account);
    });

    it('puts the hardening floor on its refusals', function (Closure $request) {
        $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
        $this->delete(route('sudo.end'));

        $response = $request($this);

        $this->assertHardeningFloor($response);
    })->with([
        'a browser' => [fn (AppTestCase $test) => $test->get($test->gated)],
        'a JSON client' => [fn (AppTestCase $test) => $test->getJson($test->gated)],
    ]);
});

describe('the replay', function () {
    it('offers what a sign-in would ask for', function () {
        $support = $this->supportsFor(Surface::SIGN_IN)[0];
        $this->signInAccount($support);
        $this->delete(route('sudo.end'));
        $this->get($this->gated);

        $response = $this->get(route('sudo'));

        $this->assertSudoPage($response, [$support->type()]);
    });

    it('grants sudo for what a sign-in would ask and returns to the intended page', function () {
        $support = $this->supportsFor(Surface::SIGN_IN)[0];
        $account = $this->signInAccount($support);
        $this->delete(route('sudo.end'));
        $this->get($this->gated);

        $response = $this->post(route('sudo.submit', ['type' => $support->type()]), $support->validProof(Surface::SIGN_IN));

        $this->assertSudoGranted($response, '/'.$this->gated);
        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.granted', 'user_id' => $account->getKey(), 'flow' => 'sudo', 'credential_type' => $support->type()]);
        $this->assertSudoPassed($this->get($this->gated));
    });

    it('never grants sudo for a first factor alone to an account holding a second factor', function () {
        $first = $this->supportsFor(Surface::SIGN_IN)[0];
        $second = $this->supportsFor(Surface::CHALLENGE)[0];
        $account = $this->createChallengedAccount($second);
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => $second->type()]), $second->validProof(Surface::CHALLENGE));
        $this->delete(route('sudo.end'));
        $this->get($this->gated);

        $response = $this->post(route('sudo.submit', ['type' => $first->type()]), $first->validProof(Surface::SIGN_IN));

        $this->assertSudoChallengeOwed($response);
        $this->assertAuthenticatedAs($account);
        $this->assertSudoRequired($this->get($this->gated));
        $this->assertSudoPage($this->get(route('sudo')), [$second->type()]);
    });

    it('grants sudo once the challenge a sign-in would demand is answered', function () {
        $first = $this->supportsFor(Surface::SIGN_IN)[0];
        $second = $this->supportsFor(Surface::CHALLENGE)[0];
        $this->createChallengedAccount($second);
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => $second->type()]), $second->validProof(Surface::CHALLENGE));
        $this->delete(route('sudo.end'));
        $this->get($this->gated);
        $this->post(route('sudo.submit', ['type' => $first->type()]), $first->validProof(Surface::SIGN_IN));

        $response = $this->post(route('sudo.submit', ['type' => $second->type()]), $second->validProof(Surface::CHALLENGE));

        $this->assertSudoGranted($response, '/'.$this->gated);
        $this->assertSudoPassed($this->get($this->gated));
    });

    it('verifies nothing without a sudo-in-progress', function () {
        $support = $this->supportsFor(Surface::SIGN_IN)[0];
        $account = $this->signInAccount($support);
        $this->delete(route('sudo.end'));

        $response = $this->post(route('sudo.submit', ['type' => $support->type()]), $support->validProof(Surface::SIGN_IN));

        $this->assertSentAwayWithoutSudoInProgress($response);
        $this->assertSudoRequired($this->get($this->gated));
        expect(SecurityEvent::query()->where('user_id', $account->getKey())->whereIn('type', ['sudo.failed', 'sudo.granted'])->where('flow', 'sudo')->count())->toBe(0);
    });

    it('refuses a wrong answer and keeps the gate shut', function () {
        $support = $this->supportsFor(Surface::SIGN_IN)[0];
        $account = $this->signInAccount($support);
        $this->delete(route('sudo.end'));
        $this->get($this->gated);

        $response = $this->post(route('sudo.submit', ['type' => $support->type()]), $support->rejectedProof(Surface::SIGN_IN));

        $this->assertSudoRefused($response, $support->type());
        $this->assertSudoRequired($this->get($this->gated));
        $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.failed', 'user_id' => $account->getKey(), 'flow' => 'sudo']);
    });

    it('sends a session nothing was demanded of away from the page', function () {
        $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);

        $this->assertSentAwayWithoutSudoInProgress($this->get(route('sudo')));
    });

    it('sends a guest away from the page and the answer', function () {
        $this->assertGuestSentAway($this->get(route('sudo')));
        $this->assertGuestSentAway($this->post(route('sudo.submit', ['type' => $this->supportsFor(Surface::SIGN_IN)[0]->type()])));
    });

    it('throttles answers past the minute\'s allowance', function () {
        $this->freezeSecond();
        $support = $this->supportsFor(Surface::SIGN_IN)[0];
        $this->signInAccount($support);
        $this->delete(route('sudo.end'));
        $this->get($this->gated);
        $this->travel(1)->minute();

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.submit')) as $ignored) {
            $this->post(route('sudo.submit', ['type' => $support->type()]), $support->rejectedProof(Surface::SIGN_IN));
        }

        $this->assertSudoThrottled($this->post(route('sudo.submit', ['type' => $support->type()]), $support->validProof(Surface::SIGN_IN)));
    });
});

describe('ending sudo', function () {
    it('ends sudo, keeping the user signed in on a new session id', function () {
        $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
        $sessionId = session()->getId();

        $response = $this->delete(route('sudo.end'));

        $this->assertSudoEnded($response);
        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseHas('user_security_events', ['type' => 'sudo.revoked', 'user_id' => $account->getKey(), 'actor' => 'user']);
        expect(session()->getId())->not->toBe($sessionId)
            ->and(session('keystone.status'))->toBe('sudo-revoked');
    });

    it('records nothing when sudo already ended', function () {
        $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
        $this->delete(route('sudo.end'));

        $response = $this->delete(route('sudo.end'));

        $this->assertSudoEnded($response);
        $this->assertAuthenticatedAs($account);
        expect(SecurityEvent::query()->where('type', 'sudo.revoked')->count())->toBe(1);
    });

    it('sends a guest away from ending sudo', function () {
        $this->assertGuestSentAway($this->delete(route('sudo.end')));
        $this->assertGuest();
    });

    it('throttles ending sudo past the minute\'s allowance', function () {
        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.change')) as $ignored) {
            $this->delete(route('sudo.end'));
        }

        $this->assertSudoThrottled($this->delete(route('sudo.end')));
    });

    it('puts the hardening floor on the end-sudo response', function () {
        $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);

        $this->assertHardeningFloor($this->delete(route('sudo.end')));
    });
});
