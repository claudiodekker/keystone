<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\CeremonySlots;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\EnrolledCredential;
use ClaudioDekker\Keystone\Methods\Initiation;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\StoredCredential;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\PendingSignIn;
use ClaudioDekker\Keystone\Tests\Fixtures\FlakyAlert;
use ClaudioDekker\Keystone\Tests\Fixtures\FormType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use ClaudioDekker\Keystone\Tests\Fixtures\RogueType;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->withMandates();
});

function startEnrollment(AppTestCase $test, string $type = 'code'): mixed
{
    $test->get(route('login.enrollment.start', ['type' => $type]));

    return Keystone::guard()->slots()->get($type, Surface::ENROLLMENT->value)['ceremony'] ?? null;
}

describe('the hold', function () {
    it('holds the sign-in of an account owing a second factor, recording why', function () {
        $account = $this->createFirstFactorAccount();

        $response = $this->passFirstFactor();

        $response->assertRedirectToRoute('login.enrollment');
        $this->assertGuest();
        $this->assertDatabaseHas('user_security_events', ['type' => 'sign_in.held', 'user_id' => $account->getKey(), 'flow' => 'sign-in', 'reason' => 'keystone.enrollment']);
    });

    it('owes only recovery codes after a proof that counts as two factors', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'passkey', multipleFactors: true));
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport('passkey'), Surface::SIGN_IN);
        $this->post(route('login.submit', ['type' => 'passkey']), ['identifier' => 'jane@example.com', ...(new FormTypeSupport('passkey'))->validProof(Surface::SIGN_IN)]);

        $response = $this->get(route('login.enrollment'));

        $response->assertRedirectToRoute('login.recovery-codes');
        $this->assertGuest();
    });

    it('signs in a proof that counts as two factors while recovery codes are optional', function () {
        config(['keystone.require_recovery_codes' => false]);
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'passkey', multipleFactors: true));
        $account = $this->createAccount();
        $this->arrangeCredential($account, new FormTypeSupport('passkey'), Surface::SIGN_IN);

        $response = $this->post(route('login.submit', ['type' => 'passkey']), ['identifier' => 'jane@example.com', ...(new FormTypeSupport('passkey'))->validProof(Surface::SIGN_IN)]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($account);
    });

    it('challenges a first factor of an account whose other credential proves two factors on its own, offering it', function () {
        $this->app->make(CredentialTypes::class)->register(new FormType(name: 'passkey', surfaces: ['challenge'], multipleFactors: true));
        config(['keystone.methods' => ['form', 'passkey']]);
        $this->createChallengedAccount(new FormTypeSupport('passkey'));

        $response = $this->passFirstFactor();

        $response->assertRedirectToRoute('login.challenge');
        $this->get(route('login.challenge'))->assertJsonPath('types.0.type', 'passkey');
    });

    it('moves a passed challenge on to the recovery codes the account still owes, recording why', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();

        $response = $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));

        $response->assertRedirectToRoute('login.enrollment');
        $this->assertGuest();
        $this->assertDatabaseHas('user_security_events', ['type' => 'sign_in.held', 'user_id' => $account->getKey(), 'flow' => 'challenge', 'reason' => 'keystone.enrollment']);
        $this->get(route('login.challenge'))->assertRedirectToRoute('login');
    });

    it('refuses a passed challenge whose account is suspended before it moves on to enrollment', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        $suspended = false;
        DB::beforeExecuting(function (string $query) use (&$suspended, $account) {
            if (! $suspended && str_contains($query, 'user_credentials')) {
                $suspended = true;
                DB::table('users')->where('id', $account->getKey())->update(['suspended_at' => now()]);
            }
        });

        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));

        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'flow' => 'challenge', 'reason' => 'keystone.barred']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'sign_in.held', 'flow' => 'challenge']);
    });

    it('drops a held sign-in once its account gains a second factor elsewhere, so the next sign-in is challenged', function () {
        $account = $this->createFirstFactorAccount();
        $this->passFirstFactor();
        $this->arrangeCredential($account, new FormTypeSupport('code'), Surface::CHALLENGE);

        $response = $this->get(route('login.enrollment'));

        $response->assertRedirectToRoute('login');
        expect(Keystone::guard()->pending())->toBeNull();
    });

    it('drops a held sign-in once its account is suspended', function () {
        $account = $this->createFirstFactorAccount();
        $this->passFirstFactor();
        DB::table('users')->where('id', $account->getKey())->update(['suspended_at' => now()]);

        $response = $this->get(route('login.enrollment'));

        $response->assertRedirectToRoute('login');
        expect(Keystone::guard()->pending())->toBeNull();
    });

    it('sends a signed-in user away from every enrollment step', function (string $method, string $route) {
        $this->withoutMandates();
        $this->signInAccount(new FormTypeSupport);

        $response = $this->{$method}(route($route, ['type' => 'code']));

        $response->assertRedirect('/');
    })->with([
        'the offer' => ['get', 'login.enrollment'],
        'the form' => ['get', 'login.enrollment.start'],
        'the answer' => ['post', 'login.enrollment.submit'],
        'cancelling' => ['delete', 'login.enrollment.cancel'],
        'the recovery codes' => ['get', 'login.recovery-codes'],
        'saving recovery codes' => ['post', 'login.recovery-codes.submit'],
    ]);
});

describe('the offer', function () {
    it('lists the types that answer a challenge, with their shapes', function () {
        $types = $this->app->make(CredentialTypes::class);
        $types->register(new FormType(name: 'passkey', surfaces: ['challenge', 'enrollment'], multipleFactors: true));
        $types->register(new FormType(name: 'phrase', surfaces: ['enrollment'], multipleFactors: true));
        config(['keystone.methods' => ['form', 'code', 'passkey', 'phrase']]);
        $this->createFirstFactorAccount();
        $this->passFirstFactor();

        $response = $this->get(route('login.enrollment'));

        $response->assertExactJson([
            'types' => [['type' => 'code', 'shape' => 'form'], ['type' => 'passkey', 'shape' => 'form']],
            'preselect' => 'code',
            'origin' => 'login',
        ]);
    });

    it('sends a type the account can\'t enroll back to the offer', function () {
        $this->createFirstFactorAccount();
        $this->passFirstFactor();

        $this->get(route('login.enrollment.start', ['type' => 'form']))->assertRedirectToRoute('login.enrollment');
        $this->post(route('login.enrollment.submit', ['type' => 'form']), ['secret' => 'x'])->assertRedirectToRoute('login.enrollment');
    });
});

describe('the ceremony', function () {
    it('starts the ceremony once, naming the account by its alert address, until the held sign-in ends', function () {
        $this->freezeSecond();
        $this->createFirstFactorAccount();
        $this->passFirstFactor();
        $ceremony = startEnrollment($this);

        $response = $this->get(route('login.enrollment.start', ['type' => 'code']));

        $response->assertExactJson(['type' => 'code', 'shape' => 'form', 'ceremony' => ['code' => $ceremony, 'account' => 'jane@example.com'], 'status' => null]);
        $this->travel(PendingSignIn::LIFETIME_SECONDS + 1)->seconds();
        expect(Keystone::guard()->slots()->get('code', Surface::ENROLLMENT->value))->toBeNull();
    });

    it('reports a ceremony that fails to start and sends the user back to the offer', function () {
        Exceptions::fake();
        $this->app->make(CredentialTypes::class)->register(new class(name: 'broken', surfaces: ['challenge', 'enrollment']) extends FormType
        {
            public function initiate(Surface $surface, string $accountName): Initiation
            {
                throw new RuntimeException('Authenticator offline.');
            }
        });
        $this->createFirstFactorAccount();
        $this->passFirstFactor();

        $response = $this->get(route('login.enrollment.start', ['type' => 'broken']));

        $response->assertRedirectToRoute('login.enrollment')->assertSessionHasErrors(['broken' => __('keystone::messages.enrollment_failed')]);
        Exceptions::assertReported(RuntimeException::class);
        expect(Keystone::guard()->slots()->get('broken', Surface::ENROLLMENT->value))->toBeNull();
    });
});

describe('the answer', function () {
    it('stores the credential and records it in one change, moving no epoch', function () {
        Notification::fake();
        $account = $this->createFirstFactorAccount();
        $this->passFirstFactor();
        $ceremony = startEnrollment($this);

        $response = $this->post(route('login.enrollment.submit', ['type' => 'code']), ['secret' => $ceremony]);

        $response->assertRedirectToRoute('login.recovery-codes');
        $this->assertDatabaseHas('user_credentials', ['user_id' => $account->getKey(), 'type' => 'code', 'served_challenge' => true]);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'has_second_factor' => true, 'credential_epoch' => 0]);
        $this->assertDatabaseHas('user_security_events', ['type' => 'credential.added', 'user_id' => $account->getKey(), 'flow' => 'enrollment', 'credential_type' => 'code']);
        Notification::assertSentOnDemandTimes(SecurityAlert::class, 1);
        expect(Keystone::guard()->slots()->get('code', Surface::ENROLLMENT->value))->toBeNull();
    });

    it('signs in once enrolled while recovery codes are optional, recording the sign-in', function () {
        config(['keystone.require_recovery_codes' => false]);
        $account = $this->createFirstFactorAccount();
        $this->passFirstFactor();
        $ceremony = startEnrollment($this);

        $response = $this->post(route('login.enrollment.submit', ['type' => 'code']), ['secret' => $ceremony]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseHas('user_security_events', ['type' => 'signed_in', 'user_id' => $account->getKey(), 'flow' => 'enrollment', 'credential_type' => 'code']);
    });

    it('refuses a wrong answer, storing nothing and flashing no secret', function () {
        $account = $this->createFirstFactorAccount();
        $this->passFirstFactor();
        startEnrollment($this);

        $response = $this->post(route('login.enrollment.submit', ['type' => 'code']), ['secret' => 'wrong']);

        $response->assertRedirectToRoute('login.enrollment.start', ['type' => 'code'])
            ->assertSessionHasErrors(['code' => __('keystone::messages.invalid_credential')])
            ->assertSessionMissing('_old_input');
        $this->assertDatabaseMissing('user_credentials', ['type' => 'code']);
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'flow' => 'enrollment', 'reason' => 'code.mismatch']);
    });

    it('refuses an answer the type fails to verify, reporting the failure and storing nothing', function () {
        Exceptions::fake();
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => throw new RuntimeException('Verifier down.'), surfaces: ['challenge', 'enrollment']));
        $this->createFirstFactorAccount();
        $this->passFirstFactor();
        startEnrollment($this, 'rogue');

        $response = $this->post(route('login.enrollment.submit', ['type' => 'rogue']), ['secret' => 'anything']);

        $response->assertSessionHasErrors(['rogue' => __('keystone::messages.invalid_credential')]);
        Exceptions::assertReported(RuntimeException::class);
        $this->assertDatabaseMissing('user_credentials', ['type' => 'rogue']);
    });

    it('refuses an answer once the account gained a second factor elsewhere, storing nothing', function () {
        $account = $this->createFirstFactorAccount();
        $this->passFirstFactor();
        $ceremony = startEnrollment($this);
        $raced = false;
        $outerLevel = DB::transactionLevel();
        DB::beforeExecuting(function (string $query, array $bindings, Connection $connection) use (&$raced, $account, $outerLevel) {
            if (! $raced && $connection->transactionLevel() > $outerLevel && str_contains($query, 'users')) {
                $raced = true;
                DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'rogue', 'served_challenge' => true]);
            }
        });

        $response = $this->post(route('login.enrollment.submit', ['type' => 'code']), ['secret' => $ceremony]);

        $response->assertSessionHasErrors(['code' => __('keystone::messages.invalid_credential')]);
        $this->assertGuest();
        $this->assertDatabaseMissing('user_credentials', ['type' => 'code']);
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'flow' => 'enrollment', 'reason' => 'keystone.second_factor_held']);
    });

    it('refuses a proof that enrolls nothing', function () {
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::proven(new StoredCredential(1, identifier: null, secret: null, label: null)), surfaces: ['challenge', 'enrollment']));
        $account = $this->createFirstFactorAccount();
        $this->passFirstFactor();
        startEnrollment($this, 'rogue');

        $this->post(route('login.enrollment.submit', ['type' => 'rogue']), ['secret' => 'anything']);

        $this->assertDatabaseMissing('user_credentials', ['type' => 'rogue']);
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'reason' => 'keystone.not_enrolled']);
    });

    it('stores the label the type gives the enrolled credential', function () {
        $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::enrolled(new EnrolledCredential(identifier: null, secret: 'seed', label: 'Phone')), surfaces: ['challenge', 'enrollment']));
        $this->createFirstFactorAccount();
        $this->passFirstFactor();
        startEnrollment($this, 'rogue');

        $this->post(route('login.enrollment.submit', ['type' => 'rogue']), ['secret' => 'anything']);

        $this->assertDatabaseHas('user_credentials', ['type' => 'rogue', 'label' => 'Phone']);
    });

    it('signs in once enrolled even when the alert can\'t be sent', function () {
        config(['keystone.require_recovery_codes' => false]);
        $account = $this->createFirstFactorAccount('broken@example.com');
        $this->passFirstFactor('broken@example.com');
        config(['keystone.notifications' => ['credential.added' => FlakyAlert::class]]);
        $ceremony = startEnrollment($this);

        $response = $this->post(route('login.enrollment.submit', ['type' => 'code']), ['secret' => $ceremony]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseCount('user_credentials', 2);
    });

    it('starts a fresh ceremony, saying why, when the answer has none running', function () {
        $this->createFirstFactorAccount();
        $this->passFirstFactor();

        $response = $this->post(route('login.enrollment.submit', ['type' => 'code']), ['secret' => 'stale']);

        $response->assertRedirectToRoute('login.enrollment.start', ['type' => 'code']);
        $this->get(route('login.enrollment.start', ['type' => 'code']))->assertJsonPath('status', __('keystone::messages.status.enrollment-expired'));
        $this->assertDatabaseMissing('user_credentials', ['type' => 'code']);
    });

    it('refuses an empty answer with an error on each required field', function () {
        $this->createFirstFactorAccount();
        $this->passFirstFactor();
        startEnrollment($this);

        $response = $this->post(route('login.enrollment.submit', ['type' => 'code']), []);

        $response->assertRedirectToRoute('login.enrollment.start', ['type' => 'code'])->assertSessionHasErrors(['secret']);
    });

    it('cancels the held sign-in, saying nobody was signed in', function () {
        $this->createFirstFactorAccount();
        $this->passFirstFactor();
        startEnrollment($this);

        $response = $this->delete(route('login.enrollment.cancel'));

        $response->assertRedirectToRoute('login')->assertSessionHas('keystone.status', 'enrollment-cancelled');
        expect(Keystone::guard()->pending())->toBeNull()
            ->and(session()->has(CeremonySlots::SESSION_KEY))->toBeFalse();
    });
});

describe('recovery codes', function () {
    beforeEach(function () {
        $this->account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->passFirstFactor();
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE));
    });

    it('stages a set in the session that every visit shows', function () {
        $codes = $this->get(route('login.recovery-codes'))->json('codes');

        $response = $this->get(route('login.recovery-codes'));

        $response->assertExactJson(['codes' => $codes]);
        expect($codes)->toHaveCount(8);
        $this->assertDatabaseCount('user_recovery_codes', 0);
    });

    it('saves the staged set once a code is typed back, recording it without an alert, and signs in', function () {
        Notification::fake();
        $codes = $this->get(route('login.recovery-codes'))->json('codes');

        $response = $this->post(route('login.recovery-codes.submit'), ['code' => strtolower($codes[5])]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($this->account);
        $this->assertDatabaseCount('user_recovery_codes', 8);
        $this->assertDatabaseHas('users', ['id' => $this->account->getKey(), 'has_recovery_codes' => true, 'credential_epoch' => 0]);
        $this->assertDatabaseHas('user_security_events', ['type' => 'recovery_codes.generated', 'user_id' => $this->account->getKey(), 'flow' => 'enrollment']);
        $this->assertDatabaseHas('user_security_events', ['type' => 'signed_in', 'user_id' => $this->account->getKey(), 'flow' => 'enrollment', 'credential_type' => 'recovery-code']);
        Notification::assertNothingSent();
        expect(session()->has(CeremonySlots::SESSION_KEY))->toBeFalse();
    });

    it('refuses a code that isn\'t one of the staged set, saving none and flashing nothing typed', function () {
        $this->get(route('login.recovery-codes'));

        $response = $this->post(route('login.recovery-codes.submit'), ['code' => 'WRONG-CODE']);

        $response->assertRedirectToRoute('login.recovery-codes')
            ->assertSessionHasErrors(['code' => __('keystone::messages.recovery_code_mismatch')])
            ->assertSessionMissing('_old_input');
        $this->assertGuest();
        $this->assertDatabaseCount('user_recovery_codes', 0);
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $this->account->getKey(), 'flow' => 'enrollment', 'reason' => 'recovery-code.mismatch']);
    });

    it('refuses the staged set once the account saved a set elsewhere, keeping that one', function () {
        $codes = $this->get(route('login.recovery-codes'))->json('codes');
        $raced = false;
        $outerLevel = DB::transactionLevel();
        DB::beforeExecuting(function (string $query, array $bindings, Connection $connection) use (&$raced, $outerLevel) {
            if (! $raced && $connection->transactionLevel() > $outerLevel && str_contains($query, 'users')) {
                $raced = true;
                $this->arrangeRecoveryCodes($this->account, count: 1);
            }
        });

        $response = $this->post(route('login.recovery-codes.submit'), ['code' => $codes[0]]);

        $response->assertSessionHasErrors(['code']);
        $this->assertGuest();
        $this->assertDatabaseCount('user_recovery_codes', 1);
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $this->account->getKey(), 'flow' => 'enrollment', 'reason' => 'keystone.recovery_codes_held']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'recovery_codes.generated']);
    });

    it('refuses an empty answer', function () {
        $this->get(route('login.recovery-codes'));

        $this->post(route('login.recovery-codes.submit'), [])->assertRedirectToRoute('login.recovery-codes')->assertSessionHasErrors(['code']);
    });

    it('never owes recovery codes while they are optional', function () {
        config(['keystone.require_recovery_codes' => false]);

        $response = $this->get(route('login.recovery-codes'));

        $response->assertRedirectToRoute('login');
    });
});

describe('demotion', function () {
    beforeEach(function () {
        Route::middleware(['web', 'auth'])->get('keystone-tests/signed-in-only', fn () => 'Signed in.');
        $this->withoutMandates();
        $this->account = $this->signInAccount(new FormTypeSupport);
        $this->withMandates();
    });

    it('holds a signed-in session that newly owes enrollment, rotating it and keeping the site data', function () {
        Keystone::guard()->slots()->put('rogue', 'sign-in', 'challenge-bytes', capSeconds: 300);
        $sessionId = session()->getId();

        $response = $this->get('keystone-tests/signed-in-only?tab=2');

        $response->assertRedirectToRoute('login.enrollment')->assertHeaderMissing('Clear-Site-Data');
        $this->assertGuest();
        expect(session()->getId())->not->toBe($sessionId)
            ->and(session()->has(CeremonySlots::SESSION_KEY))->toBeFalse()
            ->and(Keystone::guard()->pending()?->intendedUrl)->toBe('/keystone-tests/signed-in-only?tab=2');
        $this->assertDatabaseHas('user_security_events', ['type' => 'sign_in.held', 'user_id' => $this->account->getKey(), 'reason' => 'demoted']);
    });

    it('refuses a JSON request with a 403 saying what is owed', function () {
        $response = $this->getJson('keystone-tests/signed-in-only');

        $response->assertForbidden()->assertExactJson(['message' => __('keystone::messages.status.enrollment-owed'), 'reason' => 'demoted']);
    });

    it('lets the demoted session enroll and continue to the page it asked for', function () {
        config(['keystone.require_recovery_codes' => false]);
        $this->get('keystone-tests/signed-in-only');
        $ceremony = startEnrollment($this);

        $response = $this->post(route('login.enrollment.submit', ['type' => 'code']), ['secret' => $ceremony]);

        $response->assertRedirect('/keystone-tests/signed-in-only');
        $this->assertAuthenticatedAs($this->account);
    });

    it('leaves a session alone while its account owes nothing', function () {
        $this->withoutMandates();

        $this->get('keystone-tests/signed-in-only')->assertSee('Signed in.');
    });
});
