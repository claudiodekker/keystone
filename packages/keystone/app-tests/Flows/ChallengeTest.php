<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\ChallengeAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\DB;

pest()->extend(AppTestCase::class)->use(
    AppTestCase::assertions(SignInAssertions::class),
    AppTestCase::assertions(ChallengeAssertions::class),
);

beforeEach(function () {
    $this->support = $this->supportsFor(Surface::CHALLENGE)[0];
});

describe('hold', function () {
    it('holds the sign-in of an account with a second factor for the challenge, signing nobody in', function () {
        $this->createChallengedAccount($this->support);
        $sessionId = session()->getId();

        $response = $this->passFirstFactor();

        $this->assertChallengeOwed($response);
        $this->assertGuest();
        expect(session()->getId())->not->toBe($sessionId);
    });

    it('signs in an account without a second factor without a challenge', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->supportsFor(Surface::SIGN_IN)[0], Surface::SIGN_IN);

        $response = $this->passFirstFactor();

        $this->assertSignedIn($response, '/');
        $this->assertAuthenticatedAs($account);
    });

    it('treats a held session as a guest everywhere else', function () {
        $this->createChallengedAccount($this->support);
        $this->passFirstFactor();

        $this->assertSignInPage($this->get(route('login')));
        $this->assertGuest();
    });

    it('replaces the held sign-in with a newer one', function () {
        $this->createChallengedAccount($this->support);
        $john = $this->createChallengedAccount($this->support, 'john@example.com');
        $this->passFirstFactor();
        $this->passFirstFactor('john@example.com');

        $response = $this->post(route('login.challenge.submit', ['type' => $this->support->type()]), $this->support->validProof(Surface::CHALLENGE));

        $this->assertChallengePassed($response, '/');
        $this->assertAuthenticatedAs($john);
    });

    it('records the hold with the first factor\'s type', function () {
        $account = $this->createChallengedAccount($this->support);

        $this->assertChallengeOwed($this->passFirstFactor());

        $this->assertDatabaseHas('user_security_events', [
            'type' => 'sign_in.held',
            'user_id' => $account->getKey(),
            'flow' => 'sign-in',
            'credential_type' => $this->supportsFor(Surface::SIGN_IN)[0]->type(),
            'reason' => 'keystone.challenge',
        ]);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'signed_in']);
    });
});

describe('show', function () {
    it('offers the account\'s second factor', function () {
        $this->createChallengedAccount($this->support);
        $this->passFirstFactor();

        $this->assertChallengePage($this->get(route('login.challenge')), [$this->support->type()]);
    });

    it('sends a session without a held sign-in to the sign-in page', function () {
        $this->assertSentToSignIn($this->get(route('login.challenge')));
    });

    it('sends a signed-in user away', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->supportsFor(Surface::SIGN_IN)[0], Surface::SIGN_IN);
        $this->passFirstFactor();

        $this->assertSignedInSentAwayFromChallenge($this->get(route('login.challenge')));
        $this->assertAuthenticatedAs($account);
    });

    it('drops a held sign-in fifteen minutes after the hold', function () {
        $this->freezeSecond();
        $this->createChallengedAccount($this->support);
        $this->passFirstFactor();

        $this->travel(14)->minutes();
        $this->assertChallengePage($this->get(route('login.challenge')), [$this->support->type()]);

        $this->travel(1)->minutes();
        $this->assertSentToSignIn($this->get(route('login.challenge')));
    });

    it('voids a held sign-in once the account\'s credential epoch moves, recording it', function () {
        $account = $this->createChallengedAccount($this->support);
        $this->passFirstFactor();
        DB::table($account->getTable())->where($account->getKeyName(), $account->getKey())->increment('credential_epoch');

        $response = $this->get(route('login.challenge'));

        $this->assertSentToSignIn($response);
        $this->assertDatabaseHas('user_security_events', ['type' => 'sign_in.voided', 'user_id' => $account->getKey()]);
    });

    it('clears a held sign-in whose account holds no second factor any more', function () {
        $account = $this->createChallengedAccount($this->support);
        $this->passFirstFactor();
        DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', $this->support->type())->delete();

        $this->assertSentToSignIn($this->get(route('login.challenge')));

        $this->assertSentToSignIn($this->post(route('login.challenge.submit', ['type' => $this->support->type()]), $this->support->validProof(Surface::CHALLENGE)));
    });

    it('sends the user on to recover an account whose second factor no listed type answers, keeping the held sign-in', function () {
        $this->createChallengedAccount($this->support);
        $this->passFirstFactor();
        config(['keystone.methods' => [$this->supportsFor(Surface::SIGN_IN)[0]->type()]]);

        $this->assertSecondFactorUnavailable($this->get(route('login.challenge')));

        $this->assertSecondFactorUnavailable($this->get(route('login.challenge')));
    });
});

describe('submit', function () {
    it('completes the sign-in with a valid answer, sending the user on to the URL intended before the hold', function () {
        $this->freezeSecond();
        $account = $this->createChallengedAccount($this->support);
        session()->put('url.intended', '/dashboard');
        $this->passFirstFactor();
        $sessionId = session()->getId();

        $response = $this->post(route('login.challenge.submit', ['type' => $this->support->type()]), $this->support->validProof(Surface::CHALLENGE));

        $this->assertChallengePassed($response, '/dashboard');
        $this->assertAuthenticatedAs($account);
        expect(session()->getId())->not->toBe($sessionId)
            ->and(Keystone::guard()->signedInAt()?->getTimestamp())->toBe(now()->getTimestamp());
    });

    it('refuses a wrong answer, keeping the held sign-in and flashing nothing back', function () {
        $account = $this->createChallengedAccount($this->support);
        $this->passFirstFactor();

        $response = $this->post(route('login.challenge.submit', ['type' => $this->support->type()]), $this->support->rejectedProof(Surface::CHALLENGE));

        $this->assertChallengeRefused($response, $this->support->type());
        $this->assertGuest();
        expect(session()->getOldInput())->toBe([]);

        $this->assertChallengePassed($this->post(route('login.challenge.submit', ['type' => $this->support->type()]), $this->support->validProof(Surface::CHALLENGE)), '/');
        $this->assertAuthenticatedAs($account);
    });

    it('refuses invalid input, flashing nothing back', function () {
        $this->createChallengedAccount($this->support);
        $this->passFirstFactor();

        $response = $this->post(route('login.challenge.submit', ['type' => $this->support->type()]));

        $this->assertChallengeInvalid($response, array_keys($this->support->validProof(Surface::CHALLENGE)));
        expect(session()->getOldInput())->toBe([]);
    });

    it('sends a session without a held sign-in to the sign-in page', function () {
        $this->assertSentToSignIn($this->post(route('login.challenge.submit', ['type' => $this->support->type()]), $this->support->validProof(Surface::CHALLENGE)));
    });

    it('records the sign-in with the answering credential, and a wrong answer as rejected', function () {
        $account = $this->createChallengedAccount($this->support);
        $this->passFirstFactor();
        $credentialId = DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', $this->support->type())->value('id');

        $this->post(route('login.challenge.submit', ['type' => $this->support->type()]), $this->support->rejectedProof(Surface::CHALLENGE));
        $this->post(route('login.challenge.submit', ['type' => $this->support->type()]), $this->support->validProof(Surface::CHALLENGE));

        $this->assertDatabaseHas('user_security_events', [
            'type' => 'proof.rejected',
            'user_id' => $account->getKey(),
            'flow' => 'challenge',
            'credential_type' => $this->support->type(),
            'credential_id' => $credentialId,
        ]);
        $this->assertDatabaseHas('user_security_events', [
            'type' => 'signed_in',
            'user_id' => $account->getKey(),
            'flow' => 'challenge',
            'credential_type' => $this->support->type(),
            'credential_id' => $credentialId,
        ]);
    });
});

describe('cancel', function () {
    it('cancels the held sign-in, saying the user was not signed in', function () {
        $this->createChallengedAccount($this->support);
        $this->passFirstFactor();
        $sessionId = session()->getId();

        $response = $this->delete(route('login.challenge.cancel'));

        $this->assertChallengeCancelled($response);
        $this->assertGuest();
        expect(session()->getId())->not->toBe($sessionId);
        $this->assertSignInPage($this->get(route('login')), __('keystone::messages.status.sign-in-cancelled'));
        $this->assertSentToSignIn($this->get(route('login.challenge')));
    });

    it('sends a session without a held sign-in to the sign-in page', function () {
        $this->assertSentToSignIn($this->delete(route('login.challenge.cancel')));
    });
});

describe('rate limits', function () {
    beforeEach(function () {
        $this->freezeSecond();
    });

    it('throttles the account after 20 wrong answers in an hour', function () {
        $this->createChallengedAccount($this->support);
        $this->passFirstFactor();

        foreach (range(1, 20) as $i) {
            $this->travel(7)->seconds();
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"]);
            $this->assertChallengeRefused($this->post(route('login.challenge.submit', ['type' => $this->support->type()]), $this->support->rejectedProof(Surface::CHALLENGE)), $this->support->type());
        }

        $this->travel(1)->minute();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1']);
        $this->assertChallengeThrottled($this->post(route('login.challenge.submit', ['type' => $this->support->type()]), $this->support->validProof(Surface::CHALLENGE)));
        $this->assertGuest();
    });

    it('leaves the first factor\'s count alone when answers are wrong', function () {
        $this->createChallengedAccount($this->support);

        foreach (range(1, 20) as $i) {
            $this->travel(15)->seconds();
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"]);
            $this->passFirstFactor();
            $this->post(route('login.challenge.submit', ['type' => $this->support->type()]), $this->support->rejectedProof(Surface::CHALLENGE));
        }

        $this->travel(1)->minute();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1']);
        $this->assertChallengeOwed($this->passFirstFactor());
    });

    it('keeps the count across a cancel and a new hold', function () {
        $this->createChallengedAccount($this->support);
        $this->passFirstFactor();

        foreach (range(1, 20) as $i) {
            $this->travel(7)->seconds();
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"]);
            $this->post(route('login.challenge.submit', ['type' => $this->support->type()]), $this->support->rejectedProof(Surface::CHALLENGE));
        }

        $this->travel(1)->minute();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1']);
        $this->delete(route('login.challenge.cancel'));
        $this->passFirstFactor();

        $this->assertChallengeThrottled($this->post(route('login.challenge.submit', ['type' => $this->support->type()]), $this->support->validProof(Surface::CHALLENGE)));
    });

    it('throttles the eleventh cancel in a minute', function () {
        foreach (range(1, 10) as $ignored) {
            $this->delete(route('login.challenge.cancel'));
        }

        $this->assertChallengeThrottled($this->delete(route('login.challenge.cancel')));
    });
});
