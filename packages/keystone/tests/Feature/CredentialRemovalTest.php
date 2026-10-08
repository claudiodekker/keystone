<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->withoutMandates();
    config(['keystone.methods' => ['form', 'code']]);
});

/**
 * Give the signed-in account a credential of the type, returning its id.
 *
 * @param  array<string, mixed>  $columns
 */
function holdCredential(string $type, ?string $label = null, array $columns = []): int
{
    return DB::table('user_credentials')->insertGetId([
        'user_id' => Keystone::guard()->id(),
        'type' => $type,
        'label' => $label,
        'created_at' => now(),
        ...$columns,
    ]);
}

describe('the confirm step', function () {
    it('names the credential it would remove', function () {
        $this->signInAccount(new FormTypeSupport);
        $id = holdCredential('code', 'Phone');

        $response = $this->get(route('security.credentials.remove', ['credential' => $id]));

        $response->assertOk()->assertExactJson(['id' => $id, 'type' => 'code', 'label' => 'Phone', 'listed' => true]);
    });

    it('names a leftover of a type no longer listed, and a disabled credential', function (string $type, array $columns, bool $listed) {
        $this->signInAccount(new FormTypeSupport);
        $id = holdCredential($type, 'Old key', $columns);

        $response = $this->get(route('security.credentials.remove', ['credential' => $id]));

        $response->assertOk()->assertExactJson(['id' => $id, 'type' => $type, 'label' => 'Old key', 'listed' => $listed]);
    })->with([
        'a leftover' => ['uninstalled', [], false],
        'a disabled credential' => ['code', ['disabled_at' => '2026-09-01 12:00:00'], true],
    ]);

    it('sends the user back to the security page for a credential the account doesn\'t hold', function (int|string $credential) {
        $this->signInAccount(new FormTypeSupport);

        $response = $this->get(route('security.credentials.remove', ['credential' => $credential]));

        $response->assertRedirectToRoute('security');
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.credential-not-found'));
    })->with([
        'an unknown id' => [fn () => 999],
        'another account\'s credential' => [fn () => DB::table('user_credentials')->insertGetId(['user_id' => $this->createAccount('john@example.com')->getKey(), 'type' => 'code'])],
        'an id that isn\'t a number' => [fn () => 'phone'],
        'an id past the largest integer' => [fn () => '99999999999999999999'],
    ]);

    it('asks for sudo first', function () {
        $this->signInAccount(new FormTypeSupport);
        $id = holdCredential('code', 'Phone');
        $this->delete(route('sudo.end'));

        $response = $this->get(route('security.credentials.remove', ['credential' => $id]));

        $response->assertRedirectToRoute('sudo');
        expect(Keystone::guard()->sudoInProgress()->intendedUrl)->toBe(route('security.credentials.remove', ['credential' => $id], absolute: false));
    });

    it('sends a guest to sign in', function () {
        $this->get(route('security.credentials.remove', ['credential' => 1]))->assertRedirectToRoute('login');
    });

    it('limits requests to the page', function () {
        $this->signInAccount(new FormTypeSupport);
        $id = holdCredential('code', 'Phone');

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.view')) as $ignored) {
            $this->get(route('security.credentials.remove', ['credential' => $id]))->assertOk();
        }

        $this->get(route('security.credentials.remove', ['credential' => $id]))->assertTooManyRequests();
    });
});

describe('removing', function () {
    it('removes the credential, records it, alerts the owner and says so on the security page', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        $id = holdCredential('code', 'Phone');
        Notification::fake();

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseMissing('user_credentials', ['id' => $id]);
        $this->assertDatabaseHas('user_security_events', [
            'type' => 'credential.removed',
            'user_id' => $account->getKey(),
            'actor' => 'user',
            'credential_type' => 'code',
            'credential_id' => $id,
            'credential_label' => 'Phone',
        ]);
        Notification::assertSentOnDemandTimes(SecurityAlert::class, 1);
        Notification::assertSentOnDemand(SecurityAlert::class, fn (SecurityAlert $alert) => $alert->type === SecurityEventType::CREDENTIAL_REMOVED);
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.credential-removed'));
    });

    it('ends the account\'s other sessions and keeps the remover\'s, rotating its id and keeping its sudo', function () {
        $this->freezeSecond();
        $account = $this->signInAccount(new FormTypeSupport);
        $id = holdCredential('code', 'Phone');
        $sessionId = session()->getId();

        $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        expect(DB::table('users')->where('id', $account->getKey())->value('credential_epoch'))->toEqual(1)
            ->and(session()->getId())->not->toBe($sessionId);
        $this->get(route('security'))->assertOk()->assertJsonPath('sudoEndsAt', now()->addMinutes(15)->toIso8601String());
        $this->assertAuthenticatedAs($account);
    });

    it('removes a leftover and a disabled credential', function (string $type, array $columns) {
        $this->signInAccount(new FormTypeSupport);
        $id = holdCredential($type, 'Old key', $columns);

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseMissing('user_credentials', ['id' => $id]);
        $this->assertDatabaseHas('user_security_events', ['type' => 'credential.removed', 'credential_type' => $type, 'credential_id' => $id]);
    })->with([
        'a leftover' => ['uninstalled', []],
        'a disabled credential' => ['code', ['disabled_at' => '2026-09-01 12:00:00']],
    ]);

    it('removes nothing for a credential the account doesn\'t hold, and says so on the security page', function (int|string $credential) {
        $account = $this->signInAccount(new FormTypeSupport);
        $credentials = DB::table('user_credentials')->count();

        $response = $this->delete(route('security.credentials.remove.submit', ['credential' => $credential]));

        $response->assertRedirectToRoute('security');
        $this->assertDatabaseCount('user_credentials', $credentials);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.removed']);
        $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
        $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.credential-not-found'));
    })->with([
        'an unknown id' => [fn () => 999],
        'another account\'s credential' => [fn () => DB::table('user_credentials')->insertGetId(['user_id' => $this->createAccount('john@example.com')->getKey(), 'type' => 'code'])],
        'an id that isn\'t a number' => [fn () => 'phone'],
    ]);

    it('asks for sudo first, removing nothing', function () {
        $this->signInAccount(new FormTypeSupport);
        $id = holdCredential('code', 'Phone');
        $this->delete(route('sudo.end'));

        $response = $this->from(route('security.credentials.remove', ['credential' => $id]))->delete(route('security.credentials.remove.submit', ['credential' => $id]));

        $response->assertRedirectToRoute('sudo');
        $this->assertDatabaseHas('user_credentials', ['id' => $id]);
        $this->assertDatabaseMissing('user_security_events', ['type' => 'credential.removed']);
    });

    it('sends a guest to sign in', function () {
        $this->delete(route('security.credentials.remove.submit', ['credential' => 1]))->assertRedirectToRoute('login');
    });

    it('takes the change limit', function () {
        $this->signInAccount(new FormTypeSupport);

        foreach (range(1, config()->integer('keystone.rate_limits.requests_per_minute.change')) as $ignored) {
            $this->delete(route('security.credentials.remove.submit', ['credential' => 999]))->assertRedirectToRoute('security');
        }

        $this->delete(route('security.credentials.remove.submit', ['credential' => 999]))->assertTooManyRequests();
    });
});
