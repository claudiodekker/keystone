<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Jobs\SuspendAccount;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\RecoveryCodes;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Status;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->withoutMandates();
    config(['keystone.methods' => ['form', 'code']]);
    Route::middleware('web')->get('who-is-signed-in', fn () => (string) (auth()->id() ?? 'guest'));
});

/**
 * Sign the address in from the named browser, keeping its cookies.
 */
function signInRegeneratingFromBrowser(AppTestCase $test, string $browser): void
{
    $test->inBrowser($browser, fn () => $test->post(route('login.submit', ['type' => 'form']), [
        'identifier' => 'jane@example.com',
        ...(new FormTypeSupport)->validProof(Surface::SIGN_IN),
    ]));
}

/**
 * Get who the named browser is signed in as on its next request, or "guest".
 */
function signedInRegeneratingFromBrowser(AppTestCase $test, string $browser): string
{
    return $test->inBrowser($browser, fn () => $test->get('who-is-signed-in')->getContent());
}

/**
 * Get the set the session staged for the regeneration, if any.
 *
 * @return array{codes: list<string>, epoch: int}|null
 */
function stagedForRegeneration(): ?array
{
    return Keystone::guard()->slots()->get(CredentialTypes::RECOVERY_CODE, 'regeneration');
}

/**
 * Run the race once, as the first query inside the account change reaches the users table, before the change takes the account's lock.
 */
function raceBeforeTheRegenerationLock(Closure $race): void
{
    $raced = false;
    $outerLevel = DB::transactionLevel();

    DB::beforeExecuting(function (string $query, array $bindings, Connection $connection) use (&$raced, $outerLevel, $race) {
        if (! $raced && $connection->transactionLevel() > $outerLevel && str_contains($query, 'users')) {
            $raced = true;
            $race();
        }
    });
}

describe('the regeneration step', function () {
    it('stages a set of the right size and shows it again on reload', function () {
        $account = $this->signInAccount(new FormTypeSupport);

        $first = $this->get(route('security.recovery-codes.regenerate'));
        $second = $this->get(route('security.recovery-codes.regenerate'));

        $first->assertOk()->assertJsonPath('page', 'regenerate-recovery-codes')->assertJsonCount(RecoveryCodes::SET_SIZE, 'codes');
        expect($second->json('codes'))->toBe($first->json('codes'))
            ->and(stagedForRegeneration()['codes'])->toBe($first->json('codes'));
        $this->assertAuthenticatedAs($account);
    });

    it('says whether saving replaces a live set', function (bool $holdsCodes) {
        $account = $this->signInAccount(new FormTypeSupport);
        $holdsCodes && $this->arrangeRecoveryCodes($account);

        $response = $this->get(route('security.recovery-codes.regenerate'));

        $response->assertJsonPath('replaces', $holdsCodes)->assertJsonPath('status', null);
    })->with(['an account holding codes' => true, 'an account holding none' => false]);

    it('stores nothing until a code is typed back', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $codes = $this->arrangeRecoveryCodes($account);

        $this->get(route('security.recovery-codes.regenerate'));

        expect((new RecoveryCodes($account))->find($account->getKey(), $codes[0]))->not->toBeNull();
        $this->assertDatabaseMissing('user_security_events', ['type' => 'recovery_codes.generated']);
    });

    it('puts the hardening floor on the step', function () {
        $this->signInAccount(new FormTypeSupport);

        $response = $this->get(route('security.recovery-codes.regenerate'));

        $this->assertHardeningFloor($response);
    });

    it('asks for sudo first, and comes back to the step', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->delete(route('sudo.end'));

        $response = $this->get(route('security.recovery-codes.regenerate'));

        $response->assertRedirectToRoute('sudo');
        expect(Keystone::guard()->sudoInProgress()->intendedUrl)->toBe(route('security.recovery-codes.regenerate', absolute: false))
            ->and(stagedForRegeneration())->toBeNull();
    });

    it('sends a guest to sign in', function () {
        $this->get(route('security.recovery-codes.regenerate'))->assertRedirectToRoute('login');
    });

    it('takes the start limit', function () {
        $this->signInAccount(new FormTypeSupport);

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.start')) as $ignored) {
            $this->get(route('security.recovery-codes.regenerate'))->assertOk();
        }

        $this->get(route('security.recovery-codes.regenerate'))->assertTooManyRequests();
    });

    it('drops the staged set when the sudo grant ends', function (string $end) {
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.recovery-codes.regenerate'));

        $end === 'revoked' ? $this->delete(route('sudo.end')) : $this->outliveSudo();

        expect(stagedForRegeneration())->toBeNull();
    })->with(['sudo revoked' => 'revoked', 'sudo outlived' => 'outlived']);

    it('stages a different set once the staged one is gone', function () {
        $this->signInAccount(new FormTypeSupport);
        $first = $this->get(route('security.recovery-codes.regenerate'))->json('codes');
        $this->delete(route('security.recovery-codes.regenerate.cancel'));

        $second = $this->get(route('security.recovery-codes.regenerate'))->json('codes');

        expect($second)->not->toBe($first);
    });
});

describe('typing a code back', function () {
    it('replaces a live set, so only the staged codes work afterwards', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $old = $this->arrangeRecoveryCodes($account);
        $staged = $this->get(route('security.recovery-codes.regenerate'))->json('codes');

        $response = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => $staged[3]]);

        $response->assertRedirectToRoute('security');
        $codes = new RecoveryCodes($account);
        expect($codes->find($account->getKey(), $old[0]))->toBeNull()
            ->and($codes->find($account->getKey(), $staged[0]))->not->toBeNull()
            ->and($codes->remaining($account->getKey()))->toBe(RecoveryCodes::SET_SIZE)
            ->and(stagedForRegeneration())->toBeNull();
        $this->get(route('security'))->assertOk()->assertJsonPath('status', Status::RECOVERY_CODES_REGENERATED->label());
    });

    it('accepts the code as typed without dashes, spaces or case', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $staged = $this->get(route('security.recovery-codes.regenerate'))->json('codes');

        $response = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => ' '.strtolower(str_replace('-', ' ', $staged[1])).' ']);

        $response->assertRedirectToRoute('security');
        expect((new RecoveryCodes($account))->find($account->getKey(), $staged[1]))->not->toBeNull();
    });

    it('moves the epoch, signs out another session, keeps this one signed in with its sudo and alerts, when it replaces a live set', function () {
        $this->freezeSecond();
        $account = $this->signInAccount(new FormTypeSupport);
        $this->arrangeRecoveryCodes($account);
        signInRegeneratingFromBrowser($this, 'phone');
        $staged = $this->get(route('security.recovery-codes.regenerate'))->json('codes');
        Notification::fake();

        $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => $staged[0]]);

        expect($account->fresh()->getRawOriginal('credential_epoch'))->toBe(1)
            ->and(signedInRegeneratingFromBrowser($this, 'phone'))->toBe('guest');
        $this->get(route('security'))->assertOk()->assertJsonPath('sudoEndsAt', now()->addMinutes(15)->toIso8601String());
        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseHas('user_security_events', ['type' => 'recovery_codes.generated', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'recovery-code']);
        Notification::assertSentOnDemandTimes(SecurityAlert::class, 1);
        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type === SecurityEventType::RECOVERY_CODES_GENERATED);
    });

    it('leaves the epoch, the other sessions and the inbox alone for a first set', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        signInRegeneratingFromBrowser($this, 'phone');
        $staged = $this->get(route('security.recovery-codes.regenerate'))->json('codes');
        Notification::fake();

        $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => $staged[0]]);

        expect($account->fresh()->getRawOriginal('credential_epoch'))->toBe(0)
            ->and(signedInRegeneratingFromBrowser($this, 'phone'))->toBe((string) $account->getKey())
            ->and((new RecoveryCodes($account))->remaining($account->getKey()))->toBe(RecoveryCodes::SET_SIZE);
        $this->assertDatabaseHas('user_security_events', ['type' => 'recovery_codes.generated', 'user_id' => $account->getKey(), 'flow' => 'settings']);
        Notification::assertNothingSent();
    });

    it('refuses a wrong code with the mismatch message, recording it and keeping the set, the old codes and the epoch', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $old = $this->arrangeRecoveryCodes($account);
        $staged = $this->get(route('security.recovery-codes.regenerate'))->json('codes');
        Notification::fake();

        $response = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => 'not-one-of-them']);

        $response->assertRedirectToRoute('security.recovery-codes.regenerate')->assertSessionHasErrors(['code' => __('keystone::messages.recovery_code_mismatch')]);
        expect($this->get(route('security.recovery-codes.regenerate'))->json('codes'))->toBe($staged)
            ->and((new RecoveryCodes($account))->find($account->getKey(), $old[0]))->not->toBeNull()
            ->and($account->fresh()->getRawOriginal('credential_epoch'))->toBe(0);
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'recovery-code', 'reason' => 'recovery-code.mismatch']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'recovery_codes.generated']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'recovery_code.used']);
        Notification::assertNothingSent();
    });

    it('refuses an empty code on its field, counting nothing', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.recovery-codes.regenerate'));

        $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => ''])->assertSessionHasErrors('code');
        $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => ''])->assertSessionHasErrors('code');

        $this->assertDatabaseMissing('user_security_events', ['type' => 'proof.rejected']);
    });

    it('counts wrong codes in the settings flow and throttles past the hourly limit', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 2]);
        $account = $this->signInAccount(new FormTypeSupport);
        $staged = $this->get(route('security.recovery-codes.regenerate'))->json('codes');
        $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => 'wrong one']);
        $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => 'wrong two']);

        $response = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => $staged[0]]);

        $response->assertTooManyRequests();
        expect((new RecoveryCodes($account))->hasRemaining($account->getKey()))->toBeFalse();
        $this->assertDatabaseHas('user_security_events', ['type' => 'limit.tripped', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'recovery-code']);
    });

    it('gives a right code\'s attempt back', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $account = $this->signInAccount(new FormTypeSupport);
        $staged = $this->get(route('security.recovery-codes.regenerate'))->json('codes');
        $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => $staged[0]]);
        $next = $this->get(route('security.recovery-codes.regenerate'))->json('codes');

        $response = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => $next[0]]);

        $response->assertRedirectToRoute('security');
        expect((new RecoveryCodes($account))->find($account->getKey(), $next[0]))->not->toBeNull();
    });

    it('waits out the timing floor for a wrong code', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.recovery-codes.regenerate'));

        $response = $this->assertWaitsOutTimingFloor(fn () => $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => 'wrong']));

        $response->assertRedirectToRoute('security.recovery-codes.regenerate');
    });

    it('sends a code typed with nothing staged back for a fresh set, counting nothing', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $account = $this->signInAccount(new FormTypeSupport);

        $first = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => 'anything']);
        $second = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => 'anything']);

        $first->assertRedirectToRoute('security.recovery-codes.regenerate');
        $second->assertRedirectToRoute('security.recovery-codes.regenerate');
        $this->get(route('security.recovery-codes.regenerate'))->assertJsonPath('status', Status::RECOVERY_CODES_EXPIRED->label());
        $this->assertDatabaseMissing('user_security_events', ['type' => 'proof.rejected']);
        expect((new RecoveryCodes($account))->hasRemaining($account->getKey()))->toBeFalse();
    });

    it('asks for sudo first, keeping the staged set out of the account', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $staged = $this->get(route('security.recovery-codes.regenerate'))->json('codes');
        $this->delete(route('sudo.end'));

        $response = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => $staged[0]]);

        $response->assertRedirectToRoute('sudo');
        expect((new RecoveryCodes($account))->hasRemaining($account->getKey()))->toBeFalse();
    });

    it('stores nothing and counts nothing when sudo ends while the code is checked', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $account = $this->signInAccount(new FormTypeSupport);
        $staged = $this->get(route('security.recovery-codes.regenerate'))->json('codes');
        raceBeforeTheRegenerationLock(fn () => $this->outliveSudo());

        $response = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => $staged[0]]);

        $response->assertRedirectToRoute('sudo');
        expect((new RecoveryCodes($account))->hasRemaining($account->getKey()))->toBeFalse();
        $this->assertDatabaseMissing('user_security_events', ['type' => 'recovery_codes.generated']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'proof.rejected']);
    });

    it('stores nothing for an account suspended while the code is checked', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $old = $this->arrangeRecoveryCodes($account);
        $staged = $this->get(route('security.recovery-codes.regenerate'))->json('codes');
        raceBeforeTheRegenerationLock(fn () => SuspendAccount::dispatchSync($account));

        $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => $staged[0]]);

        $codes = new RecoveryCodes($account);
        expect($codes->find($account->getKey(), $staged[0]))->toBeNull()
            ->and($codes->find($account->getKey(), $old[0]))->not->toBeNull();
        $this->assertDatabaseMissing('user_security_events', ['type' => 'recovery_codes.generated']);
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'flow' => 'settings', 'reason' => 'keystone.barred']);
    });

    it('discards a set staged on an epoch the account has since left, and says so', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $old = $this->arrangeRecoveryCodes($account);
        $staged = $this->get(route('security.recovery-codes.regenerate'))->json('codes');
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        raceBeforeTheRegenerationLock(fn () => DB::table('users')->where('id', $account->getKey())->increment('credential_epoch'));

        $response = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => $staged[0]]);

        $response->assertRedirectToRoute('security.recovery-codes.regenerate');
        $codes = new RecoveryCodes($account);
        expect($codes->find($account->getKey(), $staged[0]))->toBeNull()
            ->and($codes->find($account->getKey(), $old[0]))->not->toBeNull()
            ->and(stagedForRegeneration())->toBeNull();
        $this->assertDatabaseMissing('user_security_events', ['type' => 'recovery_codes.generated']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'proof.rejected']);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 1]);
    });

    it('stores and records nothing more for a submit of a set another submit stored first', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $this->arrangeRecoveryCodes($account);
        $staged = $this->get(route('security.recovery-codes.regenerate'))->json('codes');
        Notification::fake();
        raceBeforeTheRegenerationLock(function () use ($account, $staged) {
            (new RecoveryCodes($account))->replace($account->getKey(), $staged);
            DB::table('users')->where('id', $account->getKey())->increment('credential_epoch');
        });

        $response = $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => $staged[0]]);

        $response->assertRedirectToRoute('security');
        expect(DB::table('user_security_events')->where('type', 'recovery_codes.generated')->count())->toBe(0)
            ->and($account->fresh()->getRawOriginal('credential_epoch'))->toBe(1)
            ->and((new RecoveryCodes($account))->remaining($account->getKey()))->toBe(RecoveryCodes::SET_SIZE)
            ->and(stagedForRegeneration())->toBeNull();
        Notification::assertNothingSent();
        $response->assertSessionHas(Status::SESSION_KEY, Status::RECOVERY_CODES_REGENERATED->value);
    });

    it('drops the staged set when the change fails, keeping the old codes', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $old = $this->arrangeRecoveryCodes($account);
        $staged = $this->get(route('security.recovery-codes.regenerate'))->json('codes');
        raceBeforeTheRegenerationLock(fn () => throw new RuntimeException('the database went away'));
        $this->withoutExceptionHandling();

        expect(fn () => $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => $staged[0]]))->toThrow(RuntimeException::class);

        expect(stagedForRegeneration())->toBeNull()
            ->and((new RecoveryCodes($account))->find($account->getKey(), $old[0]))->not->toBeNull();
    });

    it('flashes no code, and keeps none in the session in the clear', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $old = $this->arrangeRecoveryCodes($account);
        $staged = $this->get(route('security.recovery-codes.regenerate'))->json('codes');

        $this->post(route('security.recovery-codes.regenerate.confirm'), ['code' => $staged[0].'x']);

        $flashed = $this->flashed();
        expect($flashed)->not->toHaveKey('_old_input')
            ->and(json_encode($flashed))->not->toContain($staged[0])
            ->and(json_encode(session()->all()))->not->toContain($staged[0]);
    });
});

describe('discarding the staged set', function () {
    it('forgets it and returns to the security page, leaving the old codes alone', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $old = $this->arrangeRecoveryCodes($account);
        $staged = $this->get(route('security.recovery-codes.regenerate'))->json('codes');

        $response = $this->delete(route('security.recovery-codes.regenerate.cancel'));

        $response->assertRedirectToRoute('security');
        $codes = new RecoveryCodes($account);
        expect(stagedForRegeneration())->toBeNull()
            ->and($codes->find($account->getKey(), $old[0]))->not->toBeNull()
            ->and($codes->find($account->getKey(), $staged[0]))->toBeNull();
        $this->assertDatabaseMissing('user_security_events', ['type' => 'recovery_codes.generated']);
    });

    it('needs no sudo', function () {
        $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.recovery-codes.regenerate'));
        $this->delete(route('sudo.end'));

        $response = $this->delete(route('security.recovery-codes.regenerate.cancel'));

        $response->assertRedirectToRoute('security');
    });

    it('sends a guest to sign in', function () {
        $this->delete(route('security.recovery-codes.regenerate.cancel'))->assertRedirectToRoute('login');
    });

    it('takes the change limit', function () {
        $this->signInAccount(new FormTypeSupport);

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.change')) as $ignored) {
            $this->delete(route('security.recovery-codes.regenerate.cancel'))->assertRedirectToRoute('security');
        }

        $this->delete(route('security.recovery-codes.regenerate.cancel'))->assertTooManyRequests();
    });
});
