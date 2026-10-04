<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEventRecorded;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Sleep;

pest()->extend(AppTestCase::class)->use(AppTestCase::assertions(SignInAssertions::class));

beforeEach(function () {
    $this->withoutMandates();
    $this->support = $this->supportsFor(Surface::SIGN_IN)[0];
});

describe('show', function () {
    it('shows the sign-in page', function () {
        $this->assertSignInPage($this->get(route('login')));
    });

    it('shows that the user signed out', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);
        $this->submitSignIn($this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN));
        $this->post(route('logout'));

        $this->assertSignInPage($this->get(route('login')), __('keystone::messages.status.signed-out'));
    });

    it('sends a signed-in user away without signing them out', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);
        $this->submitSignIn($this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN));

        $this->assertSignedInSentAway($this->get(route('login')));
        $this->assertAuthenticatedAs($account);
    });
});

describe('submit', function () {
    it('signs in with an email address and a valid proof', function () {
        $this->freezeSecond();
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);
        $sessionId = session()->getId();

        $response = $this->submitSignIn($this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN));

        $this->assertSignedIn($response, '/');
        $this->assertAuthenticatedAs($account);
        expect(session()->getId())->not->toBe($sessionId)
            ->and(Keystone::guard()->signedInAt()?->getTimestamp())->toBe(now()->getTimestamp());
    });

    it('finds the account whatever the case and spacing of the typed address', function () {
        $account = $this->createAccount('jane@example.com');
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        $response = $this->submitSignIn($this->support, ' Jane@EXAMPLE.com ', $this->support->validProof(Surface::SIGN_IN));

        $this->assertSignedIn($response, '/');
        $this->assertAuthenticatedAs($account);
    });

    it('stamps the session with the credential epoch the proof was checked against', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);
        DB::table($account->getTable())->where($account->getKeyName(), $account->getKey())->update(['credential_epoch' => 3]);

        $this->submitSignIn($this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN));

        $this->assertSignedInSentAway($this->get(route('login')));
        $this->assertAuthenticatedAs($account);
    });

    it('sends the user on to the intended URL, kept as a same-origin path', function (string $intended, string $expected) {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);
        session()->put('url.intended', str_replace('{app}', rtrim((string) config('app.url'), '/'), $intended));

        $response = $this->submitSignIn($this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN));

        $this->assertSignedIn($response, $expected);
    })->with([
        'same-origin' => ['{app}/dashboard?tab=security', '/dashboard?tab=security'],
        'other origin' => ['https://evil.example/dashboard', '/'],
        'protocol-relative' => ['//evil.example/dashboard', '/'],
    ]);

    it('refuses a rejected proof without signing in', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        $response = $this->submitSignIn($this->support, 'jane@example.com', $this->support->rejectedProof(Surface::SIGN_IN));

        $this->assertSignInRefused($response);
        $this->assertGuest();
    });

    it('refuses a disabled, invalidated or ambiguous account exactly like an unknown one', function (Closure $arrange) {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);
        $arrange->call($this, $account);
        $proof = $this->support->validProof(Surface::SIGN_IN);

        $this->assertIndistinguishable(
            fn () => tap($this->submitSignIn($this->support, 'jane@example.com', $proof), $this->assertSignInRefused(...)),
            fn () => tap($this->submitSignIn($this->support, 'nobody@example.com', $proof), $this->assertSignInRefused(...)),
        );
        $this->assertGuest();
    })->with([
        'deleted' => fn ($account) => DB::table($account->getTable())->where($account->getKeyName(), $account->getKey())->update([$account->getDeletedAtColumn() => now()]),
        'invalidated' => fn ($account) => DB::table($account->getTable())->where($account->getKeyName(), $account->getKey())->update(['invalidated_at' => now()]),
        'suspended' => fn ($account) => DB::table($account->getTable())->where($account->getKeyName(), $account->getKey())->update(['suspended_at' => now()]),
        'address held by two accounts' => fn () => $this->holdAddress($this->userFactory()->create(), 'jane@example.com'),
    ]);

    it('refuses a rejected proof exactly like an unknown account', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        $this->assertIndistinguishable(
            fn () => tap($this->submitSignIn($this->support, 'jane@example.com', $this->support->rejectedProof(Surface::SIGN_IN)), $this->assertSignInRefused(...)),
            fn () => tap($this->submitSignIn($this->support, 'nobody@example.com', $this->support->rejectedProof(Surface::SIGN_IN)), $this->assertSignInRefused(...)),
        );
    });

    it('does not sign in through an unverified address of an account holding a verified one', function () {
        $account = $this->createAccount('jane@work.example');
        $this->holdAddress($account, 'jane@example.com', verified: false);
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        $response = $this->submitSignIn($this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN));

        $this->assertSignInRefused($response);
        $this->assertGuest();
    });

    it('refuses inside the timing floor, and returns early once signed in', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        $this->assertSignInRefused($this->submitSignIn($this->support, 'nobody@example.com', $this->support->validProof(Surface::SIGN_IN)));

        Sleep::assertSleptTimes(1);

        $this->assertSignedIn($this->submitSignIn($this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN)), '/');

        Sleep::assertSleptTimes(1);
    });

    it('flashes back only the identifier when refusing', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        $response = $this->submitSignIn($this->support, 'jane@example.com', [...$this->support->rejectedProof(Surface::SIGN_IN), 'extra' => 'typed']);

        $this->assertSignInRefused($response);
        expect(session()->getOldInput())->toBe(['identifier' => 'jane@example.com']);
    });

    it('flashes back only the identifier when the input is invalid', function () {
        $response = $this->submitSignIn($this->support, 'jane@example.com', ['extra' => 'typed']);

        $this->assertSignInInvalid($response, array_keys($this->support->validProof(Surface::SIGN_IN)));
        expect(session()->getOldInput())->toBe(['identifier' => 'jane@example.com']);
    });

    it('requires an identifier and the type\'s input', function () {
        $response = $this->post(route('login.submit', ['type' => $this->support->type()]));

        $this->assertSignInInvalid($response, ['identifier', ...array_keys($this->support->validProof(Surface::SIGN_IN))]);
    });

    it('refuses a type that does not serve sign-in like a rejected proof', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        $this->assertIndistinguishable(
            fn () => tap($this->submitSignIn($this->support, 'jane@example.com', $this->support->rejectedProof(Surface::SIGN_IN)), $this->assertSignInRefused(...)),
            fn () => tap($this->post(route('login.submit', ['type' => 'no-such-type']), ['identifier' => 'jane@example.com']), $this->assertSignInRefused(...)),
        );
        $this->assertGuest();
    });

    it('sends a signed-in user away without signing in again', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);
        $this->submitSignIn($this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN));
        $sessionId = session()->getId();

        $response = $this->submitSignIn($this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN));

        $this->assertSignedInSentAway($response);
        expect(session()->getId())->toBe($sessionId);
    });
});

describe('rate limits', function () {
    it('throttles the eleventh submission in a minute before validating it', function () {
        foreach (range(1, 10) as $ignored) {
            $this->post(route('login.submit', ['type' => $this->support->type()]));
        }

        $this->assertSignInThrottled($this->post(route('login.submit', ['type' => $this->support->type()])));
    });

    it('throttles a type that does not exist', function () {
        foreach (range(1, 10) as $ignored) {
            $this->post(route('login.submit', ['type' => 'no-such-type']), ['identifier' => 'jane@example.com']);
        }

        $this->assertSignInThrottled($this->post(route('login.submit', ['type' => 'no-such-type']), ['identifier' => 'jane@example.com']));
    });

    it('throttles the sixty-first view of the sign-in page in a minute', function () {
        foreach (range(1, 60) as $ignored) {
            $this->get(route('login'));
        }

        $this->assertSignInThrottled($this->get(route('login')));
    });

    it('throttles an account after 20 wrong answers in an hour from any address, exactly like an unknown one', function () {
        $this->freezeSecond();
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        foreach (range(1, 20) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"]);
            $this->assertSignInRefused($this->submitSignIn($this->support, 'jane@example.com', $this->support->rejectedProof(Surface::SIGN_IN)));
            $this->assertSignInRefused($this->submitSignIn($this->support, 'nobody@example.com', $this->support->rejectedProof(Surface::SIGN_IN)));
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1']);
        $this->assertIndistinguishable(
            fn () => tap($this->submitSignIn($this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN)), $this->assertSignInThrottled(...)),
            fn () => tap($this->submitSignIn($this->support, 'nobody@example.com', $this->support->validProof(Surface::SIGN_IN)), $this->assertSignInThrottled(...)),
        );
        $this->assertGuest();
    });

    it('neither counts a successful sign-in nor resets earlier failures', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        foreach (range(1, 19) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"]);
            $this->assertSignInRefused($this->submitSignIn($this->support, 'jane@example.com', $this->support->rejectedProof(Surface::SIGN_IN)));
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1']);
        $this->assertSignedIn($this->submitSignIn($this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN)), '/');
        $this->post(route('logout'));
        $this->assertSignInRefused($this->submitSignIn($this->support, 'jane@example.com', $this->support->rejectedProof(Surface::SIGN_IN)));

        $this->assertSignInThrottled($this->submitSignIn($this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN)));
        $this->assertGuest();
    });
});

describe('security events', function () {
    it('records the sign-in on the account\'s trail', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        $this->assertSignedIn($this->submitSignIn($this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN)), '/');

        $this->assertDatabaseHas('user_security_events', [
            'type' => 'signed_in',
            'user_id' => $account->getKey(),
            'actor' => 'user',
            'flow' => 'sign-in',
            'credential_type' => $this->support->type(),
            'credential_id' => DB::table('user_credentials')->where('user_id', $account->getKey())->value('id'),
            'reason' => null,
        ]);
    });

    it('records a rejected proof on the account\'s trail', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        $this->assertSignInRefused($this->submitSignIn($this->support, 'jane@example.com', $this->support->rejectedProof(Surface::SIGN_IN)));

        $this->assertDatabaseHas('user_security_events', [
            'type' => 'proof.rejected',
            'user_id' => $account->getKey(),
            'flow' => 'sign-in',
            'credential_type' => $this->support->type(),
        ]);
        expect(DB::table('user_security_events')->value('reason'))->toStartWith($this->support->type().'.')
            ->not->toBe($this->support->type().'.invalid_reason');
    });

    it('stores and logs nothing typed for an address no account holds', function () {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged) {
            $logged[] = [$message->message, $message->context];
        });

        $this->assertSignInRefused($this->submitSignIn($this->support, 'typed-nobody@example.com', $this->support->rejectedProof(Surface::SIGN_IN)));

        $this->assertDatabaseCount('user_security_events', 0);
        expect(json_encode($logged))->not->toContain('typed-nobody');
    });

    it('signs in as usual when recording fails', function () {
        Exceptions::fake();
        Event::listen(SecurityEventRecorded::class, fn () => throw new RuntimeException('Listener broke.'));
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        $this->assertSignedIn($this->submitSignIn($this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN)), '/');

        $this->assertAuthenticatedAs($account);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Listener broke.');
    });

    it('refuses as usual when recording fails', function () {
        Exceptions::fake();
        Event::listen(SecurityEventRecorded::class, fn () => throw new RuntimeException('Listener broke.'));
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        $this->assertSignInRefused($this->submitSignIn($this->support, 'jane@example.com', $this->support->rejectedProof(Surface::SIGN_IN)));

        $this->assertGuest();
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Listener broke.');
    });
});
