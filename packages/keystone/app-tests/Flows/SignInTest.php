<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Responses\SignInResponses;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->support = $this->supportsFor(Surface::SIGN_IN)[0];
    $this->responses = $this->responses(SignInResponses::class);
});

function signIn(AppTestCase $test, CredentialTypeSupport $support, string $identifier, array $proof)
{
    return $test->post(route('login.submit', ['type' => $support->type()]), ['identifier' => $identifier, ...$proof]);
}

describe('show', function () {
    it('shows the sign-in page', function () {
        $this->responses->assertSignInPage($this->get(route('login')));
    });

    it('sends a signed-in user away without signing them out', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);
        signIn($this, $this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN));

        $this->get(route('login'))->assertRedirect('/');

        $this->assertAuthenticatedAs($account);
    });
});

describe('submit', function () {
    it('signs in with an email address and a valid proof', function () {
        $this->freezeSecond();
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);
        $sessionId = session()->getId();

        $response = signIn($this, $this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN));

        $this->responses->assertSignedIn($response, '/');
        $this->assertAuthenticatedAs($account);
        expect(session()->getId())->not->toBe($sessionId)
            ->and(Keystone::guard()->signedInAt()?->getTimestamp())->toBe(now()->getTimestamp());
    });

    it('finds the account whatever the case and spacing of the typed address', function () {
        $account = $this->createAccount('jane@example.com');
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        signIn($this, $this->support, ' Jane@EXAMPLE.com ', $this->support->validProof(Surface::SIGN_IN));

        $this->assertAuthenticatedAs($account);
    });

    it('stamps the session with the credential epoch the proof was checked against', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);
        DB::table($account->getTable())->where($account->getKeyName(), $account->getKey())->update(['credential_epoch' => 3]);

        signIn($this, $this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN));

        $this->get(route('login'))->assertRedirect('/');
        $this->assertAuthenticatedAs($account);
    });

    it('sends the user on to the intended URL, kept as a same-origin path', function (string $intended, string $expected) {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);
        session()->put('url.intended', str_replace('{app}', rtrim((string) config('app.url'), '/'), $intended));

        $response = signIn($this, $this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN));

        $this->responses->assertSignedIn($response, $expected);
    })->with([
        'same-origin' => ['{app}/dashboard?tab=security', '/dashboard?tab=security'],
        'other origin' => ['https://evil.example/dashboard', '/'],
        'protocol-relative' => ['//evil.example/dashboard', '/'],
    ]);

    it('refuses a wrong proof without signing in', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        $response = signIn($this, $this->support, 'jane@example.com', $this->support->wrongProof(Surface::SIGN_IN));

        $this->responses->assertSignInRefused($response);
        $this->assertGuest();
    });

    it('refuses a disabled, invalidated or ambiguous account exactly like an unknown one', function (Closure $arrange) {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);
        $arrange->call($this, $account);
        $proof = $this->support->validProof(Surface::SIGN_IN);

        $this->assertIndistinguishable(
            fn () => signIn($this, $this->support, 'jane@example.com', $proof),
            fn () => signIn($this, $this->support, 'nobody@example.com', $proof),
        );
        $this->assertGuest();
    })->with([
        'deleted' => fn ($account) => DB::table($account->getTable())->where($account->getKeyName(), $account->getKey())->update([$account->getDeletedAtColumn() => now()]),
        'invalidated' => fn ($account) => DB::table($account->getTable())->where($account->getKeyName(), $account->getKey())->update(['invalidated_at' => now()]),
        'suspended' => fn ($account) => DB::table($account->getTable())->where($account->getKeyName(), $account->getKey())->update(['suspended_at' => now()]),
        'address held by two accounts' => fn () => $this->holdAddress($this->userFactory()->create(), 'jane@example.com'),
    ]);

    it('refuses a wrong proof exactly like an unknown account', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        $this->assertIndistinguishable(
            fn () => signIn($this, $this->support, 'jane@example.com', $this->support->wrongProof(Surface::SIGN_IN)),
            fn () => signIn($this, $this->support, 'nobody@example.com', $this->support->wrongProof(Surface::SIGN_IN)),
        );
    });

    it('does not sign in through an unverified address of an account holding a verified one', function () {
        $account = $this->createAccount('jane@work.example');
        $this->holdAddress($account, 'jane@example.com', verified: false);
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        $response = signIn($this, $this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN));

        $this->responses->assertSignInRefused($response);
        $this->assertGuest();
    });

    it('refuses inside the timing floor, and returns early once signed in', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        signIn($this, $this->support, 'nobody@example.com', $this->support->validProof(Surface::SIGN_IN));

        Sleep::assertSleptTimes(1);

        signIn($this, $this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN));

        Sleep::assertSleptTimes(1);
    });

    it('flashes back only the identifier', function (Closure $proof) {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        signIn($this, $this->support, 'jane@example.com', [...$proof->call($this), 'extra' => 'typed']);

        expect(session()->getOldInput())->toBe(['identifier' => 'jane@example.com']);
    })->with([
        'refused' => fn () => $this->support->wrongProof(Surface::SIGN_IN),
        'invalid' => fn () => [],
    ]);

    it('requires an identifier and the type\'s input', function () {
        $response = $this->post(route('login.submit', ['type' => $this->support->type()]));

        $response->assertRedirectToRoute('login')
            ->assertSessionHasErrors(['identifier', ...array_keys($this->support->validProof(Surface::SIGN_IN))]);
    });

    it('refuses a type that does not serve sign-in like a wrong proof', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);

        $this->assertIndistinguishable(
            fn () => signIn($this, $this->support, 'jane@example.com', $this->support->wrongProof(Surface::SIGN_IN)),
            fn () => $this->post(route('login.submit', ['type' => 'no-such-type']), ['identifier' => 'jane@example.com']),
        );
        $this->assertGuest();
    });

    it('sends a signed-in user away without signing in again', function () {
        $account = $this->createAccount();
        $this->arrangeCredential($account, $this->support, Surface::SIGN_IN);
        signIn($this, $this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN));
        $sessionId = session()->getId();

        signIn($this, $this->support, 'jane@example.com', $this->support->validProof(Surface::SIGN_IN))->assertRedirect('/');

        expect(session()->getId())->toBe($sessionId);
    });
});
