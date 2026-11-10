<?php

use ClaudioDekker\Keystone\Accounts\AccountChange;
use ClaudioDekker\Keystone\Accounts\AccountChanges;
use ClaudioDekker\Keystone\AppTests\Support\RecoveryCodeSupport;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserRecoveryCode;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\Flow;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

it('signs the held user in with one of their recovery codes, alerting the owner with the codes left', function () {
    Notification::fake();
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->answerChallenge($user, $codes);

    $alert = $this->sentAlerts()->sole()['alert'];

    $this->assertSignedIn($response, '/');
    expect($alert->type)->toBe(SecurityEventType::RECOVERY_CODE_USED)
        ->and($alert->recoveryCodesLeft)->toBe(7);
});

it('refuses a spent code at the next challenge', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $codes);

    $this->switchToFreshBrowser();
    $this->passFirstFactor($user);
    $response = $this->answerChallenge($user, $codes);

    $this->assertChallengeRefused($response, 'recovery_code', __('keystone::messages.credential-invalid'));
});

it('matches a code typed in lower case, with or without its dashes and with spaces', function (string $typed) {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    (new RecoveryCodeSupport)->arrangeCodes($user, ['7KQ2M-XW9PD-4HNRT-B3ZCF-6JVGL-8', 'D5WLC-RM8QT-2YXHN-9GKPB-F4JZV-3']);
    $this->passFirstFactor($user);

    $response = $this->post(route('login.challenge.submit', 'recovery-code'), ['recovery_code' => $typed]);

    $this->assertSignedIn($response, '/');
})->with([
    'in lower case' => ['7kq2m-xw9pd-4hnrt-b3zcf-6jvgl-8'],
    'without dashes' => ['7KQ2MXW9PD4HNRTB3ZCF6JVGL8'],
    'with spaces for dashes' => ['7KQ2M XW9PD 4HNRT B3ZCF 6JVGL 8'],
    'with spaces around it' => [" 7KQ2M-XW9PD-4HNRT-B3ZCF-6JVGL-8\n"],
]);

it("offers recovery codes after the account's other second factors", function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    (new RecoveryCodeSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->get(route('login.challenge'));

    $this->assertChallengePage($response, [
        'preselected' => $support->type()->name(),
        'types' => [
            ['name' => $support->type()->name(), 'shape' => 'form'],
            ['name' => 'recovery-code', 'shape' => 'form'],
        ],
    ]);
});

it('signs in on the first factor alone an account that holds recovery codes and no second factor', function () {
    $user = $this->createUser();
    (new RecoveryCodeSupport)->arrangeCredential(Surface::CHALLENGE, $user);

    $response = $this->passFirstFactor($user);

    $this->assertSignedIn($response, '/');
});

it('records the sign-in with the recovery code type, naming no credential', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $this->answerChallenge($user, $codes);

    $events = $this->securityEvents()->for($user);
    $signedIn = $events->last();

    expect($events->pluck('type')->all())->toBe([SecurityEventType::SIGN_IN_HELD, SecurityEventType::RECOVERY_CODE_USED, SecurityEventType::SIGNED_IN])
        ->and($signedIn->flow)->toBe(Flow::CHALLENGE)
        ->and($signedIn->credential_type)->toBe('recovery-code')
        ->and($signedIn->credential_id)->toBeNull();
});

it('refuses a code the account does not hold, recording it as a rejected recovery code', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    $response = $this->post(route('login.challenge.submit', 'recovery-code'), $codes->rejectedProof(Surface::CHALLENGE));

    $rejected = $this->securityEvents()->for($user)->last();

    $this->assertChallengeRefused($response, 'recovery_code', __('keystone::messages.credential-invalid'));
    expect($rejected->type)->toBe(SecurityEventType::PROOF_REJECTED)
        ->and($rejected->flow)->toBe(Flow::CHALLENGE)
        ->and($rejected->credential_type)->toBe('recovery-code');
});

it("accepts a recovery code once wrong answers of the other second factor spent that type's allowance", function () {
    $user = $this->createUser();
    $support = $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    foreach (range(1, $this->failedAttemptAllowance()) as $i) {
        $this->travel(7)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => "10.1.{$i}.1"])
            ->post(route('login.challenge.submit', $support->type()->name()), $support->rejectedProof(Surface::CHALLENGE));
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])->answerChallenge($user, $codes);

    $this->assertSignedIn($response, '/');
});

it('refuses even a right code as throttled once wrong codes spent their allowance', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCredential(Surface::CHALLENGE, $user);
    $this->passFirstFactor($user);

    foreach (range(1, $this->failedAttemptAllowance()) as $i) {
        $this->travel(7)->seconds();
        $this->withServerVariables(['REMOTE_ADDR' => "10.1.{$i}.1"])
            ->post(route('login.challenge.submit', 'recovery-code'), $codes->rejectedProof(Surface::CHALLENGE));
    }

    $response = $this->withServerVariables(['REMOTE_ADDR' => '10.2.0.1'])->answerChallenge($user, $codes);

    $this->assertThrottled($response);
});

it('refuses and keeps the last recovery code while recovery codes are required', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCodes($user, ['7KQ2M-XW9PD-4HNRT-B3ZCF-6JVGL-8']);
    $this->passFirstFactor($user);

    $refused = $this->answerChallenge($user, $codes);
    $again = $this->answerChallenge($user, $codes);

    $this->assertLastRecoveryCodeKept($refused);
    $this->assertLastRecoveryCodeKept($again);
});

it('keeps the attempt of a refused last recovery code counted', function () {
    config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCodes($user, ['7KQ2M-XW9PD-4HNRT-B3ZCF-6JVGL-8']);
    $this->passFirstFactor($user);
    $this->answerChallenge($user, $codes);

    $response = $this->answerChallenge($user, $codes);

    $this->assertThrottled($response);
});

it('spends the last recovery code like any other while recovery codes are not required', function () {
    config(['keystone.require_recovery_codes' => false]);
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCodes($user, ['7KQ2M-XW9PD-4HNRT-B3ZCF-6JVGL-8']);
    $this->passFirstFactor($user);

    $signedIn = $this->answerChallenge($user, $codes);

    $this->switchToFreshBrowser();
    $this->passFirstFactor($user);
    $spent = $this->answerChallenge($user, $codes);

    $this->assertSignedIn($signedIn, '/');
    $this->assertChallengeRefused($spent, 'recovery_code', __('keystone::messages.credential-invalid'));
});

it('spends a code once when another answer spends it after the first read it', function () {
    $user = $this->createUser();
    $this->supportFor(Surface::CHALLENGE)->arrangeCredential(Surface::CHALLENGE, $user);
    $codes = (new RecoveryCodeSupport)->arrangeCodes($user, ['7KQ2M-XW9PD-4HNRT-B3ZCF-6JVGL-8', 'D5WLC-RM8QT-2YXHN-9GKPB-F4JZV-3']);
    $this->passFirstFactor($user);
    $table = (new UserRecoveryCode)->getTable();
    $raced = false;

    DB::listen(function (QueryExecuted $query) use ($user, $table, &$raced) {
        if ($raced
            || ! str_starts_with($query->sql, 'select * from')
            || ! str_contains($query->sql, $table)
        ) {
            return;
        }

        $raced = true;
        $first = UserRecoveryCode::query()->where('user_id', $user->getAuthIdentifier())->oldest('id')->firstOrFail();

        app(AccountChanges::class)->change($user, fn (AccountChange $change) => $change->spendRecoveryCode($first));
    });

    $response = $this->answerChallenge($user, $codes);

    $this->assertChallengeRefused($response, 'recovery_code', __('keystone::messages.credential-invalid'));
    expect($this->securityEvents()->for($user)->pluck('type')->all())->toBe([
        SecurityEventType::SIGN_IN_HELD,
        SecurityEventType::RECOVERY_CODE_USED,
        SecurityEventType::PROOF_REJECTED,
    ]);
});
