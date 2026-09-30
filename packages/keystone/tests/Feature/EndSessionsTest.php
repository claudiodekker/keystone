<?php

use ClaudioDekker\Keystone\Actions\EndSessions;
use ClaudioDekker\Keystone\Actor;
use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    Route::middleware('web')->get('whoami', fn () => auth()->id() ?? 'guest');
    Route::middleware(['web', 'auth'])->post('admin/end-my-sessions', fn (EndSessions $endSessions) => $endSessions->handle(auth()->user(), 'jane@ops'));
});

/**
 * Leave the browser's session behind, as a command running in a process of its own does.
 */
function inConsole(): void
{
    session()->flush();
}

describe('keystone:end-sessions', function () {
    it('ends every session of the account', function () {
        $account = $this->signInAccount(new FormTypeSupport);
        inConsole();

        $this->artisan('keystone:end-sessions', ['user' => (string) $account->getKey()])->assertSuccessful();

        $response = $this->get('whoami');

        $response->assertContent('guest');
    });

    it('records that an operator ended them, and which one', function () {
        $account = $this->createAccount();

        $this->artisan('keystone:end-sessions', ['user' => (string) $account->getKey(), '--operator' => 'jane@ops'])->assertSuccessful();

        $event = SecurityEvent::query()->sole();

        expect($event->only(['type', 'user_id', 'actor', 'operator']))->toEqual([
            'type' => SecurityEventType::SESSIONS_TERMINATED,
            'user_id' => $account->getKey(),
            'actor' => Actor::OPERATOR,
            'operator' => 'jane@ops',
        ]);
    });

    it('ends every account\'s sessions with --all', function () {
        $this->signInAccount(new FormTypeSupport);
        $other = $this->createAccount('john@example.com');
        inConsole();

        $this->artisan('keystone:end-sessions', ['--all' => true])->assertSuccessful();

        $response = $this->get('whoami');

        $response->assertContent('guest');
        expect(DB::table('users')->where('id', $other->getKey())->value('credential_epoch'))->toEqual(1);
    });

    it('asks before ending every account\'s sessions in production', function () {
        $this->createAccount();
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('keystone:end-sessions', ['--all' => true])
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertFailed();

        expect(DB::table('users')->value('credential_epoch'))->toEqual(0);
    });

    it('refuses unless it names exactly one account or --all', function (array $arguments) {
        $this->createAccount();

        $this->artisan('keystone:end-sessions', $arguments)
            ->expectsOutputToContain('Name one account by its id, or pass --all.')
            ->assertFailed();

        expect(DB::table('users')->value('credential_epoch'))->toEqual(0);
    })->with([
        'neither' => [[]],
        'both' => [['user' => '1', '--all' => true]],
    ]);

    it('refuses an id no account has', function () {
        $this->artisan('keystone:end-sessions', ['user' => '999'])
            ->expectsOutputToContain('No account has the id [999].')
            ->assertFailed();

        $this->assertDatabaseCount('user_security_events', 0);
    });
});

describe('the end-sessions action', function () {
    it('keeps the session of the user who ends their own sessions signed in', function () {
        $account = $this->signInAccount(new FormTypeSupport);

        $this->post('admin/end-my-sessions');

        $response = $this->get('whoami');

        $response->assertContent((string) $account->getKey());
        expect(DB::table('users')->value('credential_epoch'))->toEqual(1);
    });
});
