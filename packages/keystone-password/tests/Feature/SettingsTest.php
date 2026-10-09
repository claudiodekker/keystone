<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\Password\AppTests\Support\PasswordTypeSupport;
use ClaudioDekker\Keystone\Password\BreachedPasswords;
use ClaudioDekker\Keystone\Password\FakeBreachedPasswords;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

pest()->extend(AppTestCase::class);

/**
 * The password the account signed in with through PasswordTypeSupport.
 */
const CURRENT_PASSWORD = 'correct horse battery staple';

/**
 * A new password strong enough for an account without a second factor.
 */
const NEW_PASSWORD = 'tq8#vbnz-wx4!kp-lantern';

beforeEach(function () {
    $this->withoutMandates();
    app()->instance(BreachedPasswords::class, new FakeBreachedPasswords);
});

function givePassword(Model&KeystoneUser $account, string $password): int
{
    return DB::table('user_credentials')->insertGetId([
        'user_id' => $account->getKey(),
        'type' => 'password',
        'secret' => Crypt::encryptString(Hash::make($password)),
    ]);
}

function submitPasswordForm(AppTestCase $test, array $input): TestResponse
{
    $test->get(route('security.enroll', ['type' => 'password']));

    return $test->post(route('security.enroll.submit', ['type' => 'password']), $input);
}

function passwordOf(Model&KeystoneUser $account): string
{
    return Crypt::decryptString(DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'password')->sole()->secret);
}

function signsInWith(AppTestCase $test, string $password): bool
{
    $test->post(route('logout'));
    $test->post(route('login.submit', ['type' => 'password']), ['identifier' => 'jane@example.com', 'password' => $password]);

    return auth()->check();
}

/**
 * Run the change once core has read the account's passwords for the answer, as a request committing between that read and the lock would.
 */
function whilePasswordIsChecked(Closure $change): void
{
    $armed = true;

    DB::listen(function (QueryExecuted $query) use (&$armed, $change) {
        if (! $armed || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, DB::getQueryGrammar()->wrap('type').' = ?') || ! in_array('password', $query->bindings, true)) {
            return;
        }

        $armed = false;

        $change();
    });
}

describe('the set-up step', function () {
    it('reports no held password for an account without one', function () {
        $this->signInAccount(new FormTypeSupport);

        $response = $this->get(route('security.enroll', ['type' => 'password']));

        $response->assertOk()->assertJsonPath('type', 'password')->assertJsonPath('held', []);
    });

    it('reports the held password as removable while another way to sign in remains', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $id = givePassword($account, CURRENT_PASSWORD);

        $response = $this->get(route('security.enroll', ['type' => 'password']));

        $response->assertOk()->assertJsonPath('held', [['id' => $id, 'label' => null, 'removable' => true]]);
    });

    it('reports the held password as not removable when it is the only way to sign in', function () {
        $account = $this->signInAccount(new PasswordTypeSupport);
        $id = DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'password')->value('id');

        $response = $this->get(route('security.enroll', ['type' => 'password']));

        $response->assertOk()->assertJsonPath('held', [['id' => $id, 'label' => null, 'removable' => false]]);
    });

    it('refuses the step and its submit while passwords are not listed on sign-in, storing nothing', function (array $methods) {
        config(['keystone.methods' => $methods]);
        $account = $this->signInAccount(new FormTypeSupport);

        $this->get(route('security.enroll', ['type' => 'password']))
            ->assertRedirectToRoute('security')
            ->assertSessionHasErrors(['password' => __('keystone-password::messages.unsupported')]);
        $this->post(route('security.enroll.submit', ['type' => 'password']), ['password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD])
            ->assertRedirectToRoute('security')
            ->assertSessionHasErrors(['password' => __('keystone-password::messages.unsupported')]);

        $this->assertDatabaseMissing('user_credentials', ['user_id' => $account->getKey(), 'type' => 'password']);
    })->with([
        'listed on enrollment only' => [['form', 'password' => ['enrollment']]],
        'not listed at all' => [['form']],
    ]);

    it('offers no password set-up on the security page while passwords are not listed on sign-in', function () {
        config(['keystone.methods' => ['form', 'password' => ['enrollment']]]);
        $this->signInAccount(new FormTypeSupport);

        $response = $this->get(route('security'));

        expect(collect($response->json('types'))->firstWhere('type', 'password')['enrollable'])->toBeFalse();
    });
});

describe('adding a password', function () {
    it('adds a first password without a current one, records credential.added with an alert and moves no epoch', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        Notification::fake();

        $response = submitPasswordForm($this, ['password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);

        $response->assertRedirectToRoute('security')->assertSessionHasNoErrors();
        expect(Hash::check(NEW_PASSWORD, passwordOf($account)))->toBeTrue();
        $this->assertDatabaseHas('user_security_events', ['type' => 'credential.added', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'password']);
        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type === SecurityEventType::CREDENTIAL_ADDED);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.enrolled'));
    });

    it('adds a password over a disabled one as a first password: deletes it, records credential.added and moves no epoch', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $disabled = givePassword($account, CURRENT_PASSWORD);
        DB::table('user_credentials')->where('id', $disabled)->update(['disabled_at' => now()]);

        $response = submitPasswordForm($this, ['password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);

        $response->assertRedirectToRoute('security')->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('user_credentials', ['id' => $disabled]);
        expect(Hash::check(NEW_PASSWORD, passwordOf($account)))->toBeTrue();
        $this->assertDatabaseHas('user_security_events', ['type' => 'credential.added', 'user_id' => $account->getKey(), 'credential_type' => 'password']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.replaced']);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.enrolled'));
    });

    it('signs in with the added password', function () {
        $this->signInAccount(new FormTypeSupport);
        submitPasswordForm($this, ['password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);

        expect(signsInWith($this, NEW_PASSWORD))->toBeTrue();
    });

    it('ignores a current password sent with a first password', function () {
        $account = $this->signInAccount(new FormTypeSupport);

        $response = submitPasswordForm($this, ['current_password' => 'anything at all', 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);

        $response->assertRedirectToRoute('security')->assertSessionHasNoErrors();
        expect(Hash::check(NEW_PASSWORD, passwordOf($account)))->toBeTrue();
    });

    it('refuses a new password its rules refuse as a validation error, counting no failed attempt', function (array $input) {
        $account = $this->signInAccount(new FormTypeSupport);

        $response = submitPasswordForm($this, $input);

        $response->assertRedirectToRoute('security.enroll', ['type' => 'password'])->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('user_credentials', ['user_id' => $account->getKey(), 'type' => 'password']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'proof.rejected']);
    })->with([
        'unconfirmed' => [['password' => NEW_PASSWORD, 'password_confirmation' => 'something else entirely']],
        'too short' => [['password' => 'tq8#vbn', 'password_confirmation' => 'tq8#vbn']],
        'missing' => [[]],
    ]);

    it('adds one password when two first passwords arrive at once', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $this->get(route('security.enroll', ['type' => 'password']));
        whilePasswordIsChecked(fn () => givePassword($account, 'added by the other request'));

        $response = $this->post(route('security.enroll.submit', ['type' => 'password']), ['password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);

        $response->assertRedirectToRoute('security.enroll', ['type' => 'password'])->assertSessionHasErrors(['password' => __('keystone::messages.invalid_credential')]);
        expect(Hash::check('added by the other request', passwordOf($account)))->toBeTrue();
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'flow' => 'settings', 'credential_type' => 'password', 'reason' => 'keystone.superseded']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.added']);
    });
});

describe('changing the password', function () {
    it('changes the password given the current one, records credential.replaced with an alert, moves the epoch and says password-changed', function () {
        $account = $this->signInAccount(new PasswordTypeSupport);
        Notification::fake();

        $response = submitPasswordForm($this, ['current_password' => CURRENT_PASSWORD, 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);

        $response->assertRedirectToRoute('security')->assertSessionHasNoErrors();
        expect(Hash::check(NEW_PASSWORD, passwordOf($account)))->toBeTrue();
        $this->assertDatabaseHas('user_security_events', ['type' => 'credential.replaced', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'password']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.added']);
        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type === SecurityEventType::CREDENTIAL_REPLACED);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 1]);
        $this->assertAuthenticatedAs($account);
        $this->get(route('security'))
            ->assertJsonPath('status', __('keystone::messages.status.password-changed'))
            ->assertJsonPath('offersSignOutOthers', false);
    });

    it('signs in with the new password and refuses the old one', function () {
        $this->signInAccount(new PasswordTypeSupport);
        submitPasswordForm($this, ['current_password' => CURRENT_PASSWORD, 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);

        expect(signsInWith($this, CURRENT_PASSWORD))->toBeFalse()
            ->and(signsInWith($this, NEW_PASSWORD))->toBeTrue();
    });

    it('refuses a wrong or missing current password as a rejected proof in the settings flow, keeping the password and the epoch', function (array $current) {
        $account = $this->signInAccount(new PasswordTypeSupport);

        $response = submitPasswordForm($this, [...$current, 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);

        $response->assertRedirectToRoute('security.enroll', ['type' => 'password'])->assertSessionHasErrors(['password' => __('keystone::messages.invalid_credential')]);
        expect(Hash::check(CURRENT_PASSWORD, passwordOf($account)))->toBeTrue();
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'password', 'reason' => 'password.mismatch']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.replaced']);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
    })->with([
        'wrong' => [['current_password' => 'wrong '.CURRENT_PASSWORD]],
        'missing' => [[]],
        'empty' => [['current_password' => '']],
    ]);

    it('refuses a current password sent as an array as a validation error, counting no failed attempt', function () {
        $account = $this->signInAccount(new PasswordTypeSupport);

        $response = submitPasswordForm($this, ['current_password' => [CURRENT_PASSWORD], 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);

        $response->assertRedirectToRoute('security.enroll', ['type' => 'password'])->assertSessionHasErrors('current_password');
        expect(Hash::check(CURRENT_PASSWORD, passwordOf($account)))->toBeTrue();
        $this->assertDatabaseMissing('user_security_events', ['type' => 'proof.rejected']);
    });

    it('waits out the timing floor on a wrong current password', function () {
        $this->signInAccount(new PasswordTypeSupport);
        $this->get(route('security.enroll', ['type' => 'password']));

        $response = $this->assertWaitsOutTimingFloor(fn () => $this->post(route('security.enroll.submit', ['type' => 'password']), ['current_password' => 'wrong '.CURRENT_PASSWORD, 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]));

        $response->assertRedirectToRoute('security.enroll', ['type' => 'password']);
    });

    it('counts a wrong current password toward the settings limit', function () {
        config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
        $account = $this->signInAccount(new PasswordTypeSupport);
        submitPasswordForm($this, ['current_password' => 'wrong '.CURRENT_PASSWORD, 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);

        $response = $this->post(route('security.enroll.submit', ['type' => 'password']), ['current_password' => CURRENT_PASSWORD, 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);

        $response->assertTooManyRequests();
        expect(Hash::check(CURRENT_PASSWORD, passwordOf($account)))->toBeTrue();
    });

    it('refuses the second of two changes proved against the same password, changing it once', function () {
        $account = $this->signInAccount(new PasswordTypeSupport);
        $this->get(route('security.enroll', ['type' => 'password']));
        whilePasswordIsChecked(function () use ($account) {
            DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'password')->delete();
            givePassword($account, 'changed by the other request');
        });

        $response = $this->post(route('security.enroll.submit', ['type' => 'password']), ['current_password' => CURRENT_PASSWORD, 'password' => NEW_PASSWORD, 'password_confirmation' => NEW_PASSWORD]);

        $response->assertRedirectToRoute('security.enroll', ['type' => 'password'])->assertSessionHasErrors(['password' => __('keystone::messages.invalid_credential')]);
        expect(Hash::check('changed by the other request', passwordOf($account)))->toBeTrue();
        $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'flow' => 'settings', 'credential_type' => 'password', 'reason' => 'keystone.superseded']);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.replaced']);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
    });
});

describe('removing the password', function () {
    it('removes the password behind sudo while another way to sign in remains, records credential.removed with an alert, moves the epoch and says password-removed', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $id = givePassword($account, CURRENT_PASSWORD);
        Notification::fake();

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseMissing('user_credentials', ['id' => $id]);
        $this->assertDatabaseHas('user_security_events', ['type' => 'credential.removed', 'user_id' => $account->getKey(), 'credential_type' => 'password', 'credential_id' => $id]);
        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type === SecurityEventType::CREDENTIAL_REMOVED);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 1]);
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.password-removed'));
    });

    it('confirms the removal on its own step first', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $id = givePassword($account, CURRENT_PASSWORD);

        $response = $this->get(route('security.credentials.remove', ['credential' => $id]));

        $response->assertOk()->assertJsonPath('type', 'password');
        $this->assertDatabaseHas('user_credentials', ['id' => $id]);
    });

    it('asks for sudo first, removing nothing', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $id = givePassword($account, CURRENT_PASSWORD);
        $this->delete(route('sudo.end'));

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $response->assertRedirectToRoute('sudo');
        $this->assertDatabaseHas('user_credentials', ['id' => $id]);
    });

    it('keeps the password when it is the only way to sign in', function () {
        $account = $this->signInAccount(new PasswordTypeSupport);
        $id = DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'password')->value('id');

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $response->assertRedirectToRoute('security.credentials.remove', ['credential' => $id])->assertSessionHasErrors(['credential' => __('keystone::messages.last_sign_in_credential')]);
        $this->assertDatabaseHas('user_credentials', ['id' => $id]);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
    });

    it('removes a leftover password once passwords are no longer listed, still saying password-removed', function () {
        config(['keystone.methods' => ['form', 'code']]);
        $account = $this->signInAccount(new FormTypeSupport);
        $id = givePassword($account, CURRENT_PASSWORD);

        $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $this->assertDatabaseMissing('user_credentials', ['id' => $id]);
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.password-removed'));
    });

    it('says the credential was not found for a password the account no longer holds', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $id = givePassword($account, CURRENT_PASSWORD);
        DB::table('user_credentials')->where('id', $id)->delete();

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.removed']);
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.credential-not-found'));
    });
});
