<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\CeremonySlots;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use ClaudioDekker\Keystone\Tests\Fixtures\RogueType;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Sleep;

pest()->extend(AppTestCase::class);

describe('the hold', function () {
    it('signs in a type that represents multiple factors without a challenge', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'passkey', surfaces: ['sign-in', 'challenge'], multipleFactors: true));
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport('passkey'), Surface::SIGN_IN);
        $this->arrangeCredential($account, new FormTypeSupport('code'), Surface::CHALLENGE);

        $response = $this->post(route('login.submit', ['type' => 'passkey']), ['identifier' => 'jane@example.com', ...(new FormTypeSupport('passkey'))->validProof(Surface::SIGN_IN)]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($account);
    });

    it('signs in without a challenge an account whose only challenge credential is of the first factor\'s type', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'both', surfaces: ['sign-in', 'challenge']));
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport('both'), Surface::CHALLENGE);

        $response = $this->post(route('login.submit', ['type' => 'both']), ['identifier' => 'jane@example.com', ...(new FormTypeSupport('both'))->validProof(Surface::SIGN_IN)]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($account);
    });

    it('signs in an account whose only second factor is of a type keystone.methods no longer lists', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        config(['keystone.methods' => ['form']]);

        $response = $this->passFirstFactor();

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($account);
    });

    it('closes every ceremony slot when it holds the sign-in', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        Keystone::guard()->slots()->put('rogue', 'sign-in', 'challenge-bytes', capSeconds: 300);

        $this->passFirstFactor();

        expect(session()->has(CeremonySlots::SESSION_KEY))->toBeFalse();
    });
});

describe('the challenge page', function () {
    it('offers the challenge types the account holds, leaving out the first factor\'s type', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'both', surfaces: ['sign-in', 'challenge']));
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'unheld', surfaces: ['challenge']));
        $this->app->instance('keystone.test-support.both', new FormTypeSupport('both'));
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->arrangeCredential($account, new FormTypeSupport('both'), Surface::CHALLENGE);
        $this->post(route('login.submit', ['type' => 'both']), ['identifier' => 'jane@example.com', ...(new FormTypeSupport('both'))->validProof(Surface::SIGN_IN)]);

        $this->get(route('login.challenge'))->assertExactJson([
            'types' => [['type' => 'code', 'shape' => 'form']],
            'preselect' => 'code',
        ]);

        $this->passFirstFactor();

        $this->get(route('login.challenge'))->assertExactJson([
            'types' => [['type' => 'code', 'shape' => 'form'], ['type' => 'both', 'shape' => 'form']],
            'preselect' => 'code',
        ]);
    });

    it('shows inside the timing floor', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();

        $this->assertWaitsOutTimingFloor(fn () => $this->get(route('login.challenge')))->assertOk();
    });

    it('drops a held sign-in that expired without recording it as voided', function () {
        $this->freezeSecond();
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        $this->travel(15)->minutes();

        $this->get(route('login.challenge'))->assertRedirectToRoute('login');

        $this->assertDatabaseMissing('user_security_events', ['type' => 'sign_in.voided']);
    });

    it('drops a held sign-in once its account is suspended', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        DB::table('users')->where('id', $account->getKey())->update(['suspended_at' => now()]);

        $this->get(route('login.challenge'))->assertRedirectToRoute('login');

        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE))->assertRedirectToRoute('login');
        $this->assertGuest();
        $this->assertDatabaseHas('user_security_events', ['type' => 'sign_in.voided', 'user_id' => $account->getKey()]);
    });

    it('drops a held sign-in stamped after now, as when the clock moved back', function () {
        $this->freezeSecond();
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        $this->travel(-1)->seconds();

        $response = $this->get(route('login.challenge'));

        $response->assertRedirectToRoute('login');
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sign_in.voided']);
    });

    it('sends a signed-in user away from answering and cancelling', function (string $method, string $route) {
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $this->passFirstFactor();

        $response = $this->{$method}(route($route, ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($account);
    })->with(['answering' => ['post', 'login.challenge.submit'], 'cancelling' => ['delete', 'login.challenge.cancel']]);
});

describe('answers', function () {
    it('refuses an answer of the first factor\'s type, counting it and recording why', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'both', surfaces: ['sign-in', 'challenge']));
        $this->app->instance('keystone.test-support.both', new FormTypeSupport('both'));
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->arrangeCredential($account, new FormTypeSupport('both'), Surface::SIGN_IN);
        $this->post(route('login.submit', ['type' => 'both']), ['identifier' => 'jane@example.com', ...(new FormTypeSupport('both'))->validProof(Surface::SIGN_IN)]);

        $response = $this->post(route('login.challenge.submit', ['type' => 'both']), (new FormTypeSupport('both'))->validProof(Surface::CHALLENGE));

        $response->assertSessionHasErrors(['both' => __('keystone::messages.invalid_credential')]);
        $this->assertGuest();
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'flow' => 'challenge', 'credential_type' => 'both', 'reason' => 'keystone.first_factor']);
        $this->post(route('login.challenge.submit', ['type' => 'both']), (new FormTypeSupport('both'))->validProof(Surface::CHALLENGE))->assertTooManyRequests();
    });

    it('refuses a type that serves no challenge without counting or recording it', function (string $type) {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();

        $this->post(route('login.challenge.submit', ['type' => $type]), ['secret' => 'typed'])
            ->assertRedirectToRoute('login.challenge')
            ->assertSessionHasErrors([$type => __('keystone::messages.invalid_credential')]);

        $this->assertDatabaseMissing('user_security_events', ['type' => 'proof.rejected']);
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE))->assertRedirect('/');
    })->with(['a sign-in type' => 'form', 'no such type' => 'no-such-type']);

    it('refuses a type keystone.methods leaves off the challenge', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        config(['keystone.methods' => ['form', 'code' => ['sign-in']]]);

        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE))
            ->assertSessionHasErrors(['code' => __('keystone::messages.invalid_credential')]);

        $this->assertGuest();
    });

    it('refuses an answer when the type fails, and reports the failure', function () {
        Exceptions::fake();
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => throw new RuntimeException('Broken method.'), surfaces: ['challenge']));
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'rogue']);
        $this->passFirstFactor();

        $this->post(route('login.challenge.submit', ['type' => 'rogue']))->assertSessionHasErrors(['rogue' => __('keystone::messages.invalid_credential')]);

        $this->assertGuest();
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'Broken method.');
    });

    it('refuses a proof naming another account\'s credential', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $theirs = DB::table('user_credentials')->insertGetId(['user_id' => $this->createAccount('john@example.com')->getKey(), 'type' => 'rogue']);
        DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'rogue']);
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::proven(new StoredCredential($theirs, identifier: null, secret: null, label: null)), surfaces: ['challenge']));
        $this->passFirstFactor();

        $this->post(route('login.challenge.submit', ['type' => 'rogue']))->assertSessionHasErrors('rogue');

        $this->assertGuest();
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'credential_id' => null, 'reason' => 'keystone.foreign_credential']);
    });

    it('stores the secret an advanced proof moves its credential on to', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $id = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'rogue', 'secret' => Crypt::encryptString('step-1')]);
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::advanced(new StoredCredential($id, identifier: null, secret: 'step-1', label: null), 'step-2'), surfaces: ['challenge']));
        $this->passFirstFactor();

        $this->post(route('login.challenge.submit', ['type' => 'rogue']))->assertRedirect('/');

        $this->assertAuthenticatedAs($account);
        expect(Crypt::decryptString(DB::table('user_credentials')->where('id', $id)->value('secret')))->toBe('step-2');
    });

    it('refuses an advanced proof once another proof moved its credential on first', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $id = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'rogue', 'secret' => Crypt::encryptString('step-1')]);
        $this->app->make(CredentialTypes::class)->register(new RogueType(function () use ($id) {
            DB::table('user_credentials')->where('id', $id)->update(['secret' => Crypt::encryptString('step-2')]);

            return Proof::advanced(new StoredCredential($id, identifier: null, secret: 'step-1', label: null), 'step-2');
        }, surfaces: ['challenge']));
        $this->passFirstFactor();

        $this->post(route('login.challenge.submit', ['type' => 'rogue']))->assertSessionHasErrors('rogue');

        $this->assertGuest();
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'credential_id' => $id, 'reason' => 'keystone.superseded']);
    });

    it('refuses inside the timing floor, and returns early once signed in', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();

        $this->assertWaitsOutTimingFloor(fn () => $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->rejectedProof(Surface::CHALLENGE)));

        Sleep::fake();

        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE))->assertRedirect('/');

        Sleep::assertNeverSlept();
    });

    it('closes every ceremony slot when it signs in', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        Keystone::guard()->slots()->put('rogue', 'challenge', 'challenge-bytes', capSeconds: 300);

        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));

        $this->assertAuthenticated();
        expect(session()->has(CeremonySlots::SESSION_KEY))->toBeFalse();
    });
});

describe('cancel', function () {
    it('closes every ceremony slot', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        Keystone::guard()->slots()->put('rogue', 'challenge', 'challenge-bytes', capSeconds: 300);

        $this->delete(route('login.challenge.cancel'))->assertRedirectToRoute('login');

        expect(session()->has(CeremonySlots::SESSION_KEY))->toBeFalse();
    });
});

describe('rate limits', function () {
    it('counts a held session\'s requests against its account from any address', function () {
        $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();

        foreach (range(1, 10) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"]);
            $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->rejectedProof(Surface::CHALLENGE));
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1']);
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE))->assertTooManyRequests();
    });

    it('refuses a guessable type\'s 101st wrong answer in a day, with the hourly allowance left', function () {
        $this->freezeSecond();
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'otp', surfaces: ['challenge'], sharesFailedAttempts: true));
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);
        $this->arrangeCredential($account, new FormTypeSupport('otp'), Surface::CHALLENGE);

        foreach (range(1, 5) as $hour) {
            $this->passFirstFactor();

            foreach (range(1, 20) as $ignored) {
                $this->travel(7)->seconds();
                $this->post(route('login.challenge.submit', ['type' => 'otp']), (new FormTypeSupport('otp'))->rejectedProof(Surface::CHALLENGE))->assertSessionHasErrors('otp');
            }

            $this->travel(1)->hour();
        }

        $this->passFirstFactor();

        $this->post(route('login.challenge.submit', ['type' => 'otp']), (new FormTypeSupport('otp'))->validProof(Surface::CHALLENGE))->assertTooManyRequests();
        $this->assertGuest();
    });
});
