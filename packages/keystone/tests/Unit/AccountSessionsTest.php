<?php

use ClaudioDekker\Keystone\AccountSessions;
use ClaudioDekker\Keystone\Hmac;
use ClaudioDekker\Keystone\KeystoneGuard;
use ClaudioDekker\Keystone\RememberMe;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\EncryptedStore;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config([
        'auth.guards.web.driver' => 'keystone',
        'auth.providers.users.model' => User::class,
    ]);
});

/**
 * Handle one request from the browser holding the session id, on the database session driver storing sessions encrypted as JSON.
 */
function requestOnEncryptedJsonSessions(?string &$sessionId, Closure $callback): mixed
{
    $handler = new DatabaseSessionHandler(DB::connection(), 'sessions', 120, app());
    $session = new EncryptedStore('keystone_session', $handler, app('encrypter'), $sessionId, 'json');
    $session->start();
    app()->instance('session.store', $session);
    app()->forgetInstance('auth.driver');
    Auth::forgetGuards();

    $result = $callback(Auth::guard('web'));

    $session->save();
    $sessionId = $session->getId();

    return $result;
}

it('reads another session stored encrypted as JSON, and leaves this session to store the id it rotated to', function () {
    $user = User::factory()->create();
    [$laptop, $phone] = [null, null];
    requestOnEncryptedJsonSessions($laptop, fn (KeystoneGuard $guard) => $guard->signIn($user));
    requestOnEncryptedJsonSessions($phone, fn (KeystoneGuard $guard) => $guard->signIn($user, RememberMe::ASKED));
    $signedInAs = $laptop;

    $listed = requestOnEncryptedJsonSessions($laptop, function (KeystoneGuard $guard) use ($user) {
        $guard->rotateFor($user);

        return $guard->sessions($user)->live();
    });

    expect(json_decode(Crypt::decrypt(base64_decode(DB::table('sessions')->where('id', $phone)->value('payload'))), true))->toHaveKey('keystone_epoch_web', 0)
        ->and($listed)->toHaveCount(2)
        ->and($listed[1]->handle)->toBe(Hmac::make(AccountSessions::HANDLE_PURPOSE, $phone))
        ->and($listed[1]->rememberTokenId)->toBe(DB::table('user_remember_tokens')->value('id'))
        ->and($laptop)->not->toBe($signedInAs)
        ->and(DB::table('sessions')->where('id', $laptop)->value('user_id'))->toBe($user->getKey())
        ->and(requestOnEncryptedJsonSessions($laptop, fn (KeystoneGuard $guard) => $guard->user()?->getKey()))->toBe($user->getKey());
});
