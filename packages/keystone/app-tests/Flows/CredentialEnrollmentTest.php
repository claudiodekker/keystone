<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\CredentialEnrollmentAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SudoAssertions;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\InitiateShape;
use ClaudioDekker\Keystone\Methods\Initiation;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Support\Facades\Exceptions;

pest()->extend(AppTestCase::class)->use(
    AppTestCase::assertions(CredentialEnrollmentAssertions::class),
    AppTestCase::assertions(SudoAssertions::class),
);

beforeEach(function () {
    $this->withoutMandates();
    $this->support = $this->enrollmentSupports()[0];
});

it('shows the type\'s form, then stores the credential it proves and keeps the user signed in', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $type = $this->support->type();

    $this->assertCredentialEnrollmentForm($this->get(route('security.enroll', ['type' => $type])), $type);
    $response = $this->post(route('security.enroll.submit', ['type' => $type]), $this->support->validEnrollment($this->enrollmentCeremony($type)));

    $this->assertCredentialEnrolled($response);
    $this->assertDatabaseHas('user_credentials', ['user_id' => $account->getKey(), 'type' => $type]);
    $this->assertDatabaseHas('user_security_events', ['type' => 'credential.added', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => $type]);
    $this->assertAuthenticatedAs($account);
});

it('refuses a wrong answer, storing nothing and keeping the ceremony', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $type = $this->support->type();
    $this->get(route('security.enroll', ['type' => $type]));
    $ceremony = $this->enrollmentCeremony($type);

    $response = $this->post(route('security.enroll.submit', ['type' => $type]), $this->support->rejectedEnrollment($ceremony));

    $this->assertCredentialEnrollmentRefused($response, $type);
    $this->assertDatabaseMissing('user_credentials', ['user_id' => $account->getKey(), 'type' => $type]);
    expect($this->enrollmentCeremony($type))->toBe($ceremony);
});

it('asks for sudo before it starts a ceremony or stores anything', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $type = $this->support->type();
    $this->delete(route('sudo.end'));

    $this->assertSudoRequired($this->get(route('security.enroll', ['type' => $type])));
    $this->assertSudoRequired($this->post(route('security.enroll.submit', ['type' => $type]), $this->support->validEnrollment('anything')));

    expect($this->enrollmentCeremony($type))->toBeNull();
    $this->assertDatabaseMissing('user_credentials', ['user_id' => $account->getKey(), 'type' => $type]);
});

it('sends an answer with no ceremony back to the type\'s form, which says the enrollment expired', function () {
    $account = $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $type = $this->support->type();

    $response = $this->post(route('security.enroll.submit', ['type' => $type]), []);

    $this->assertCredentialEnrollmentExpired($response, $type);
    $this->assertCredentialEnrollmentRestarted($this->get(route('security.enroll', ['type' => $type])), $type);
    $this->assertDatabaseMissing('user_credentials', ['user_id' => $account->getKey(), 'type' => $type]);
});

it('cancels the ceremony, so the next visit starts another', function () {
    $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);
    $type = $this->support->type();
    $this->get(route('security.enroll', ['type' => $type]));

    $response = $this->delete(route('security.enroll.cancel', ['type' => $type]));

    $this->assertCredentialEnrollmentCancelled($response);
    expect($this->enrollmentCeremony($type))->toBeNull();
});

it('sends the user back to the security page when a type\'s ceremony can\'t start', function () {
    Exceptions::fake();
    $this->types()->register(new class implements CredentialType
    {
        public function name(): string
        {
            return 'broken';
        }

        public function surfaces(): array
        {
            return [Surface::ENROLLMENT->value => InitiateShape::FORM];
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
            throw new RuntimeException('The ceremony could not start.');
        }

        public function verify(Surface $surface, array $input, array $credentials, mixed $ceremony = null): Proof
        {
            return Proof::rejected('broken.unreachable');
        }
    });
    config(['keystone.methods' => null]);
    $this->signInAccount($this->supportsFor(Surface::SIGN_IN)[0]);

    $response = $this->get(route('security.enroll', ['type' => 'broken']));

    $this->assertCredentialEnrollmentNotStarted($response, 'broken');
    Exceptions::assertReported(RuntimeException::class);
});
