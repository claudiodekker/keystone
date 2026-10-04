<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\ChallengeAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\EnrollmentAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\RecoveryCodesAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Initiation;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\RecoveryCodeType;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;

pest()->extend(AppTestCase::class)->use(
    AppTestCase::assertions(SignInAssertions::class),
    AppTestCase::assertions(ChallengeAssertions::class),
    AppTestCase::assertions(EnrollmentAssertions::class),
    AppTestCase::assertions(RecoveryCodesAssertions::class),
);

beforeEach(function () {
    $this->withMandates();
    $this->support = $this->enrollmentSupports()[0];
});

describe('hold', function () {
    it('holds the sign-in of an account without a second factor for enrollment, signing nobody in', function () {
        $this->createFirstFactorAccount();

        $response = $this->passFirstFactor();

        $this->assertEnrollmentOwed($response);
        $this->assertGuest();
    });

    it('signs in an account without a second factor while the app requires neither', function () {
        $this->withoutMandates();
        $account = $this->createFirstFactorAccount();

        $response = $this->passFirstFactor();

        $this->assertSignedIn($response, '/');
        $this->assertAuthenticatedAs($account);
    });

    it('holds an account that holds a second factor but no recovery codes after the challenge', function () {
        $this->createChallengedAccount($this->support);
        $this->passFirstFactor();

        $response = $this->post(route('login.challenge.submit', ['type' => $this->support->type()]), $this->support->validProof(Surface::CHALLENGE));

        $this->assertEnrollmentOwedAfterChallenge($response);
        $this->assertRecoveryCodesOwed($this->get(route('login.enrollment')));
        $this->assertGuest();
    });

    it('sends a session without a sign-in held at enrollment to the sign-in page', function () {
        $this->assertSentToSignInFromEnrollment($this->get(route('login.enrollment')));
        $this->assertSentToSignInFromEnrollment($this->get(route('login.recovery-codes')));
    });

    it('holds for enrollment an account whose second factor\'s type is no longer listed, whatever codes it holds', function () {
        $account = $this->createChallengedAccount($this->support);
        $this->arrangeRecoveryCodes($account);
        config(['keystone.methods' => [$this->supportsFor(Surface::SIGN_IN)[0]->type()]]);

        $response = $this->passFirstFactor();

        $this->assertEnrollmentOwed($response);
        $this->assertGuest();
    });
});

describe('second factor', function () {
    it('offers the types an account can enroll as its second factor', function () {
        $this->createFirstFactorAccount();
        $this->passFirstFactor();

        $response = $this->get(route('login.enrollment'));

        $this->assertEnrollmentPage($response, array_map(fn ($support) => $support->type(), $this->enrollmentSupports()));
    });

    it('shows the same ceremony on every visit to the type\'s form', function () {
        $this->createFirstFactorAccount();
        $this->passFirstFactor();
        $this->get(route('login.enrollment.start', ['type' => $this->support->type()]));
        $ceremony = $this->enrollmentCeremony($this->support->type());

        $response = $this->get(route('login.enrollment.start', ['type' => $this->support->type()]));

        $this->assertEnrollmentForm($response, $this->support->type());
        expect($this->enrollmentCeremony($this->support->type()))->toEqual($ceremony);
    });

    it('stores the enrolled credential, then asks for recovery codes', function () {
        $account = $this->createFirstFactorAccount();
        $this->passFirstFactor();

        $response = $this->enrollSecondFactor($this->support);

        $this->assertRecoveryCodesOwed($response);
        $this->assertGuest();
        $this->assertDatabaseHas('user_credentials', ['user_id' => $account->getKey(), 'type' => $this->support->type()]);
        $this->assertDatabaseHas('user_security_events', ['type' => 'credential.added', 'user_id' => $account->getKey(), 'flow' => 'enrollment', 'credential_type' => $this->support->type()]);
    });

    it('completes the sign-in after enrolling while the app requires no recovery codes', function () {
        config(['keystone.require_recovery_codes' => false]);
        $account = $this->createFirstFactorAccount();
        $this->passFirstFactor();

        $response = $this->enrollSecondFactor($this->support);

        $this->assertEnrollmentCompleted($response, '/');
        $this->assertAuthenticatedAs($account);
    });

    it('refuses a wrong answer, storing nothing', function () {
        $account = $this->createFirstFactorAccount();
        $this->passFirstFactor();
        $this->get(route('login.enrollment.start', ['type' => $this->support->type()]));

        $response = $this->post(route('login.enrollment.submit', ['type' => $this->support->type()]), $this->support->rejectedEnrollment($this->enrollmentCeremony($this->support->type())));

        $this->assertEnrollmentRefused($response, $this->support->type());
        $this->assertGuest();
        $this->assertDatabaseMissing('user_credentials', ['user_id' => $account->getKey(), 'type' => $this->support->type()]);
    });

    it('refuses an empty answer with an error for each field', function () {
        $this->createFirstFactorAccount();
        $this->passFirstFactor();
        $this->get(route('login.enrollment.start', ['type' => $this->support->type()]));
        $fields = array_keys($this->support->validEnrollment($this->enrollmentCeremony($this->support->type())));

        $response = $this->post(route('login.enrollment.submit', ['type' => $this->support->type()]), []);

        $this->assertEnrollmentInvalid($response, $this->support->type(), $fields);
    });

    it('sends the user back to the offer when the type\'s ceremony can\'t start', function () {
        Exceptions::fake();
        $this->types()->register(new class implements CredentialType
        {
            public function name(): string
            {
                return 'broken';
            }

            public function surfaces(): array
            {
                return ['challenge' => InitiateShape::FORM, 'enrollment' => InitiateShape::FORM];
            }

            public function representsMultipleFactors(): bool
            {
                return false;
            }

            public function sharesFailedAttempts(): bool
            {
                return false;
            }

            public function configFailures(): array
            {
                return [];
            }

            public function rules(Surface $surface): array
            {
                return [];
            }

            public function initiate(Surface $surface, string $accountName): ?Initiation
            {
                throw new RuntimeException('Authenticator offline.');
            }

            public function verify(Surface $surface, array $input, array $credentials, mixed $ceremony = null): Proof
            {
                return Proof::rejected('broken.mismatch');
            }
        });
        config(['keystone.methods' => null]);
        $this->createFirstFactorAccount();
        $this->passFirstFactor();

        $response = $this->get(route('login.enrollment.start', ['type' => 'broken']));

        $this->assertEnrollmentNotStarted($response, 'broken');
        Exceptions::assertReported(RuntimeException::class);
    });

    it('starts a fresh ceremony when the answer has none running', function () {
        $this->createFirstFactorAccount();
        $this->passFirstFactor();

        $response = $this->post(route('login.enrollment.submit', ['type' => $this->support->type()]), []);

        $this->assertEnrollmentExpired($response, $this->support->type());
        $this->assertEnrollmentRestarted($this->get(route('login.enrollment.start', ['type' => $this->support->type()])), $this->support->type());
    });

    it('enrolls nothing twice when the answer is sent again', function () {
        $this->createFirstFactorAccount();
        $this->passFirstFactor();
        $this->get(route('login.enrollment.start', ['type' => $this->support->type()]));
        $answer = $this->support->validEnrollment($this->enrollmentCeremony($this->support->type()));
        $this->post(route('login.enrollment.submit', ['type' => $this->support->type()]), $answer);

        $response = $this->post(route('login.enrollment.submit', ['type' => $this->support->type()]), $answer);

        $this->assertRecoveryCodesOwed($response);
        $this->assertDatabaseCount('user_credentials', 2);
    });

    it('cancels the held sign-in, saying nobody was signed in', function () {
        $this->createFirstFactorAccount();
        $this->passFirstFactor();

        $response = $this->delete(route('login.enrollment.cancel'));

        $this->assertEnrollmentCancelled($response);
        $this->assertGuest();
        $this->assertSignInPage($this->get(route('login')), __('keystone::messages.status.enrollment-cancelled'));
    });
});

describe('recovery codes', function () {
    it('shows the same staged set on every visit', function () {
        $this->createFirstFactorAccount();
        $this->passFirstFactor();
        $this->enrollSecondFactor($this->support);
        $this->get(route('login.recovery-codes'));
        $codes = $this->stagedRecoveryCodes();

        $response = $this->get(route('login.recovery-codes'));

        $this->assertRecoveryCodesPage($response, $codes);
        expect($this->stagedRecoveryCodes())->toBe($codes);
    });

    it('saves the staged set when one of its codes is typed back, completing the sign-in', function () {
        $account = $this->createFirstFactorAccount();
        $this->passFirstFactor();
        $this->enrollSecondFactor($this->support);
        $this->get(route('login.recovery-codes'));
        $codes = $this->stagedRecoveryCodes();

        $response = $this->post(route('login.recovery-codes.submit'), [RecoveryCodeType::FIELD => $codes[3]]);

        $this->assertRecoveryCodesSaved($response, '/');
        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseCount('user_recovery_codes', count($codes));
        $this->assertDatabaseHas('user_security_events', ['type' => 'recovery_codes.generated', 'user_id' => $account->getKey(), 'flow' => 'enrollment']);
    });

    it('refuses a code that isn\'t one of the staged set, saving none', function () {
        $this->createFirstFactorAccount();
        $this->passFirstFactor();
        $this->enrollSecondFactor($this->support);
        $this->get(route('login.recovery-codes'));

        $response = $this->post(route('login.recovery-codes.submit'), [RecoveryCodeType::FIELD => 'WRONG-CODE']);

        $this->assertRecoveryCodeRefused($response);
        $this->assertGuest();
        $this->assertDatabaseCount('user_recovery_codes', 0);
    });

    it('sends an account that still owes its second factor on to enroll it', function () {
        $this->createFirstFactorAccount();
        $this->passFirstFactor();

        $this->assertSecondFactorOwed($this->get(route('login.recovery-codes')));
    });

    it('holds an account for its recovery codes alone while the app requires no second factor, then signs it in', function () {
        config(['keystone.require_second_factor' => false]);
        $account = $this->createFirstFactorAccount();
        $this->assertEnrollmentOwed($this->passFirstFactor());
        $this->assertRecoveryCodesOwed($this->get(route('login.enrollment')));
        $this->get(route('login.recovery-codes'));
        $codes = $this->stagedRecoveryCodes();

        $response = $this->post(route('login.recovery-codes.submit'), [RecoveryCodeType::FIELD => $codes[0]]);

        $this->assertRecoveryCodesSaved($response, '/');
        $this->assertAuthenticatedAs($account);
    });
});

describe('demotion', function () {
    beforeEach(function () {
        Route::middleware(['web', 'auth'])->get('keystone-app-tests/signed-in-only', fn () => 'Signed in.');
    });

    it('ends a signed-in session whose account newly owes enrollment, sending it to sign in again', function () {
        $this->withoutMandates();
        $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
        $this->withMandates();

        $response = $this->get('keystone-app-tests/signed-in-only');

        $this->assertDemotedToSignIn($response);
        $this->assertGuest();
        $this->assertDatabaseHas('user_security_events', ['type' => 'session.ended', 'user_id' => $account->getKey(), 'reason' => 'demoted']);
    });

    it('refuses a JSON request from a session whose account newly owes enrollment', function () {
        $this->withoutMandates();
        $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
        $this->withMandates();

        $this->assertDemotedJsonRefused($this->getJson('keystone-app-tests/signed-in-only'));
    });

    it('tells the user on the sign-in page to sign in again and enroll', function () {
        $this->withoutMandates();
        $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
        $this->withMandates();
        $this->get('keystone-app-tests/signed-in-only');

        $this->assertSignInPage($this->get(route('login')), __('keystone::messages.status.enrollment-owed'));
    });

    it('lets the user sign in again, enroll and get back to the page they asked for', function () {
        $this->withoutMandates();
        $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
        config(['keystone.require_second_factor' => true]);
        $this->get('keystone-app-tests/signed-in-only');
        $this->passFirstFactor();

        $response = $this->enrollSecondFactor($this->support);

        $this->assertEnrollmentCompleted($response, '/keystone-app-tests/signed-in-only');
        $this->assertAuthenticatedAs($account);
    });
});
