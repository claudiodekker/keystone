<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;

pest()->extend(AppTestCase::class);

describe('the offer', function () {
    it('offers recovery codes after the account\'s second factors while it holds one', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->arrangeRecoveryCodes($account, count: 1);
        $this->passFirstFactor();

        $response = $this->get(route('login.challenge'));

        $response->assertExactJson([
            'types' => [['type' => 'code', 'shape' => 'form'], ['type' => CredentialTypes::RECOVERY_CODE, 'shape' => 'form']],
            'preselect' => 'code',
        ]);
    });

    it('sends a session to sign in once its account holds recovery codes but no second factor', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        $this->arrangeRecoveryCodes($account);
        $this->passFirstFactor();
        DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'code')->delete();

        $this->get(route('login.challenge'))->assertRedirectToRoute('login');

        $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => 'ANY-CODE'])->assertRedirectToRoute('login');
    });
});

describe('answers', function () {
    it('accepts a code in any case, with or without its dashes', function (Closure $typed) {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        [$code] = $this->arrangeRecoveryCodes($account);
        $this->passFirstFactor();

        $response = $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $typed($code)]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($account);
    })->with([
        'as shown' => fn (string $code) => $code,
        'in lowercase' => fn (string $code) => strtolower($code),
        'without dashes' => fn (string $code) => str_replace('-', '', $code),
        'with spaces for dashes' => fn (string $code) => str_replace('-', ' ', $code),
    ]);

    it('accepts a code imported from Fortify exactly as typed', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        DB::table('user_recovery_codes')->insert(['user_id' => $account->getKey(), 'code_hash' => hash('sha256', 'aBcDeFgHiJ-kLmNoPqRsT')]);
        $this->passFirstFactor();

        $response = $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => 'aBcDeFgHiJ-kLmNoPqRsT']);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($account);
    });

    it('refuses a code imported from Fortify in another case or without its dash', function (string $typed) {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        DB::table('user_recovery_codes')->insert(['user_id' => $account->getKey(), 'code_hash' => hash('sha256', 'aBcDeFgHiJ-kLmNoPqRsT')]);
        $this->passFirstFactor();

        $response = $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $typed]);

        $response->assertSessionHasErrors([CredentialTypes::RECOVERY_CODE => __('keystone::messages.invalid_credential')]);
        $this->assertGuest();
    })->with(['in uppercase' => 'ABCDEFGHIJ-KLMNOPQRST', 'without its dash' => 'aBcDeFgHiJkLmNoPqRsT']);

    it('refuses a wrong code, counting it against recovery codes alone and recording why', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        [$code] = $this->arrangeRecoveryCodes($account);
        $this->passFirstFactor();

        $response = $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => 'WRONG-CODE']);

        $response->assertSessionHasErrors([CredentialTypes::RECOVERY_CODE => __('keystone::messages.invalid_credential')]);
        $this->assertDatabaseHas('user_security_events', [
            'type' => 'proof.rejected',
            'user_id' => $account->getKey(),
            'flow' => 'challenge',
            'credential_type' => CredentialTypes::RECOVERY_CODE,
            'reason' => 'recovery-code.mismatch',
        ]);
        $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $code])->assertTooManyRequests();
        $this->post(route('login.challenge.submit', ['type' => 'code']), (new FormTypeSupport('code'))->validProof(Surface::CHALLENGE))->assertRedirect('/');
    });

    it('keeps the last code while recovery codes are required, counting it and recording why', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        [$last] = $this->arrangeRecoveryCodes($account, count: 1);
        $this->passFirstFactor();

        $response = $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $last]);

        $response->assertSessionHasErrors([CredentialTypes::RECOVERY_CODE => __('keystone::messages.last_recovery_code')]);
        $this->assertGuest();
        $this->assertDatabaseCount('user_recovery_codes', 1);
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'credential_type' => CredentialTypes::RECOVERY_CODE, 'reason' => 'keystone.last_recovery_code']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'recovery_code.used']);
        $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $last])->assertTooManyRequests();
    });

    it('spends a code while it is not the last, with recovery codes required', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        [$code] = $this->arrangeRecoveryCodes($account, count: 2);
        $this->passFirstFactor();

        $response = $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $code]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseCount('user_recovery_codes', 1);
    });

    it('refuses a code another submission spent first, recording no use', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        [$code] = $this->arrangeRecoveryCodes($account);
        $this->passFirstFactor();
        $raced = false;
        DB::beforeExecuting(function (string $query) use (&$raced) {
            if (! $raced && str_starts_with($query, 'delete') && str_contains($query, 'user_recovery_codes')) {
                $raced = true;
                DB::table('user_recovery_codes')->delete();
            }
        });

        $response = $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $code]);

        $response->assertSessionHasErrors([CredentialTypes::RECOVERY_CODE => __('keystone::messages.invalid_credential')]);
        $this->assertGuest();
        $this->assertDatabaseMissing('user_security_events', ['type' => 'recovery_code.used']);
    });

    it('keeps the code of an account suspended while it answers', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        [$code] = $this->arrangeRecoveryCodes($account);
        $this->passFirstFactor();
        $suspended = false;
        $outerLevel = DB::transactionLevel();
        DB::beforeExecuting(function (string $query, array $bindings, Connection $connection) use (&$suspended, $account, $outerLevel) {
            if (! $suspended && $connection->transactionLevel() > $outerLevel && str_contains($query, 'users')) {
                $suspended = true;
                DB::table('users')->where('id', $account->getKey())->update(['suspended_at' => now()]);
            }
        });

        $response = $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $code]);

        $response->assertSessionHasErrors([CredentialTypes::RECOVERY_CODE => __('keystone::messages.invalid_credential')]);
        $this->assertGuest();
        $this->assertDatabaseCount('user_recovery_codes', 8);
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'credential_type' => CredentialTypes::RECOVERY_CODE, 'reason' => 'keystone.barred']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'recovery_code.used']);
    });

    it('spends the last code when recovery codes are optional', function () {
        config(['keystone.require_recovery_codes' => false]);
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        [$last] = $this->arrangeRecoveryCodes($account, count: 1);
        $this->passFirstFactor();

        $response = $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $last]);

        $response->assertRedirect('/');
        $this->assertAuthenticatedAs($account);
        $this->assertDatabaseCount('user_recovery_codes', 0);
    });

    it('records the code\'s use and the sign-in, alerting the owner with the codes left', function () {
        Notification::fake();
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        [$code] = $this->arrangeRecoveryCodes($account);
        $this->passFirstFactor();

        $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $code]);

        $this->assertDatabaseHas('user_security_events', [
            'type' => 'recovery_code.used',
            'user_id' => $account->getKey(),
            'flow' => 'challenge',
            'credential_type' => CredentialTypes::RECOVERY_CODE,
        ]);
        $this->assertDatabaseHas('user_security_events', [
            'type' => 'signed_in',
            'user_id' => $account->getKey(),
            'flow' => 'challenge',
            'credential_type' => CredentialTypes::RECOVERY_CODE,
            'credential_id' => null,
        ]);
        Notification::assertSentOnDemand(SecurityAlert::class, function (SecurityAlert $alert, array $channels, object $notifiable) {
            return $alert->type === SecurityEventType::RECOVERY_CODE_USED
                && $alert->remainingRecoveryCodes === 7
                && $notifiable->routes['mail'] === 'jane@example.com';
        });
    });

    it('refuses inside the timing floor, and returns early once signed in', function () {
        $account = $this->createChallengedAccount(new FormTypeSupport('code'));
        [$code] = $this->arrangeRecoveryCodes($account);
        $this->passFirstFactor();
        Sleep::fake();

        $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => 'WRONG-CODE']);

        Sleep::assertSleptTimes(1);

        $this->post(route('login.challenge.submit', ['type' => CredentialTypes::RECOVERY_CODE]), ['code' => $code])->assertRedirect('/');

        Sleep::assertSleptTimes(1);
    });
});
