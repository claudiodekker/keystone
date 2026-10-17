<?php

use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Session\Flow;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

it('refuses the right secret as throttled after the allowance of wrong secrets in an hour', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance());

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInThrottled($response);
});

it('counts again once the hour has passed', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance());

    $this->travel(60 * 60)->seconds();
    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($response, '/');
});

it('leaves earlier failures counted after a success in between', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $half = intdiv($this->failedAttemptAllowance(), 2);
    $this->submitRejectedProofs($support, 'ada@example.com', $half);

    $signIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($signIn, '/');

    Auth::forgetGuards();
    $this->flushSession();
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance() - $half);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInThrottled($response);
});

it('answers a real account, a made-up one and a garbage credential type alike once throttled', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance());
    $this->submitRejectedProofs($support, 'grace@example.com', $this->failedAttemptAllowance());

    $real = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $madeUp = $this->flushSession()->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    foreach (range(1, $this->requestAllowance(StepKind::SUBMIT)) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post(route('login.submit', 'garbage'));
    }

    $garbage = $this->flushSession()->post(route('login.submit', 'garbage'));

    $this->assertSignInThrottled($real);
    $this->assertSignInThrottled($madeUp);
    $this->assertSignInThrottled($garbage);
    $this->assertIndistinguishable($real, $madeUp);
    $this->assertIndistinguishable($real, $garbage);
});

it('counts two spellings of a made-up address that would name the same account as one', function () {
    $support = $this->supportFor(Surface::SIGN_IN);
    $half = intdiv($this->failedAttemptAllowance(), 2);
    $this->submitRejectedProofs($support, 'Grace@Example.com', $half);
    $this->submitRejectedProofs($support, 'grace@example.com', $this->failedAttemptAllowance() - $half);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => ' GRACE@EXAMPLE.COM ',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInThrottled($response);
});

it('keeps a count each for two made-up addresses that differ only in an accent', function () {
    $support = $this->supportFor(Surface::SIGN_IN);
    $this->submitRejectedProofs($support, 'rené@example.com', $this->failedAttemptAllowance());

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'rene@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});

it('records limit.tripped for the account once, on the first refused attempt of the window', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance() + 3);

    $events = $this->securityEvents()->for($user);
    $tripped = $events->where('type', SecurityEventType::LIMIT_TRIPPED)->values();

    expect($events->pluck('type')->all())->toBe([...array_fill(0, $this->failedAttemptAllowance(), SecurityEventType::PROOF_REJECTED), SecurityEventType::LIMIT_TRIPPED])
        ->and($tripped[0]->reason)->toBe('keystone.failed_attempt_limit')
        ->and($tripped[0]->credential_type)->toBe($support->type()->name())
        ->and($tripped[0]->flow)->toBe(Flow::SIGN_IN);
});

it('logs limit.tripped once for a made-up address, and stores the typed address nowhere', function () {
    $support = $this->supportFor(Surface::SIGN_IN);

    $this->submitRejectedProofs($support, 'grace@example.com', $this->failedAttemptAllowance() + 3);

    $lines = collect($this->securityEvents()->logged())->where('type', 'limit.tripped')->values();

    expect($this->securityEvents()->stored())->toBeEmpty()
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['user_id'])->toBeNull()
        ->and($lines[0]['reason'])->toBe('keystone.failed_attempt_limit')
        ->and(json_encode($lines, JSON_THROW_ON_ERROR))->not->toContain('grace');
});

it("dispatches Laravel's Lockout event once when the limit trips", function () {
    Event::fake([Lockout::class]);
    $support = $this->supportFor(Surface::SIGN_IN);

    $this->submitRejectedProofs($support, 'grace@example.com', $this->failedAttemptAllowance() + 3);

    Event::assertDispatchedTimes(Lockout::class, 1);
});

it('keeps refusing when a lockout listener fails, and reports the failure', function () {
    Event::listen(Lockout::class, fn () => throw new RuntimeException('The listener failed.'));
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance());

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInThrottled($response);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The listener failed.');
});

it('gives a sign-in its own count back, and no more', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance() - 1);

    $signIn = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignedIn($signIn, '/');

    Auth::forgetGuards();
    $givenBack = $this->flushSession()->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInRefused($givenBack, __('keystone::messages.credentials-rejected'));

    $spent = $this->flushSession()->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    $this->assertSignInThrottled($spent);
});

it('keeps a count per account, so another account still signs in once one is throttled', function () {
    $ada = $this->createUser();
    $this->addVerifiedAddress($ada, 'ada@example.com');
    $grace = $this->createUser();
    $this->addVerifiedAddress($grace, 'grace@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $ada)->arrangeCredential(Surface::SIGN_IN, $grace);
    $this->submitRejectedProofs($support, 'ada@example.com', $this->failedAttemptAllowance());

    $throttled = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $ada),
    ]);

    $signIn = $this->flushSession()->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'grace@example.com',
        ...$support->validProof(Surface::SIGN_IN, $grace),
    ]);

    $this->assertSignInThrottled($throttled);
    $this->assertSignedIn($signIn, '/');
});

it('keeps a count per credential type, so another type still signs the account in once one is throttled', function () {
    $supports = $this->supportsFor(Surface::SIGN_IN);

    if (count($supports) < 2) {
        $this->markTestSkipped('Fewer than two installed credential types with test support serve sign-in.');
    }

    [$spent, $other] = $supports;
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $spent->arrangeCredential(Surface::SIGN_IN, $user);
    $other->arrangeCredential(Surface::SIGN_IN, $user);
    $this->submitRejectedProofs($spent, 'ada@example.com', $this->failedAttemptAllowance());

    $throttled = $this->post(route('login.submit', $spent->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$spent->validProof(Surface::SIGN_IN, $user),
    ]);

    $signIn = $this->flushSession()->post(route('login.submit', $other->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$other->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInThrottled($throttled);
    $this->assertSignedIn($signIn, '/');
});
