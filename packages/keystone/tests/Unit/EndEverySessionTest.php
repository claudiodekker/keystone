<?php

use ClaudioDekker\Keystone\Jobs\EndEverySession;
use ClaudioDekker\Keystone\SecurityEventRecorded;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

function credentialEpochOf(User $user): int
{
    return (int) DB::table('users')->where('id', $user->getKey())->value('credential_epoch');
}

it('moves every account\'s epoch on by one', function () {
    $this->freezeSecond();
    $jane = User::factory()->create();
    $john = User::factory()->create();
    DB::table('users')->where('id', $john->getKey())->update(['credential_epoch' => 4]);

    (new EndEverySession)->handle();

    expect(credentialEpochOf($jane))->toBe(1)
        ->and(credentialEpochOf($john))->toBe(5)
        ->and(DB::table('users')->pluck('credential_epoch_moved_at')->unique()->all())->toBe([now()->toDateTimeString()]);
});

it('ends the mover\'s own session too', function () {
    $user = User::factory()->create();
    Auth::guard('web')->signIn($user);

    (new EndEverySession)->handle();

    Auth::forgetGuards();
    expect(Auth::guard('web')->user())->toBeNull();
});

it('logs one event about nobody, naming the operator', function () {
    config([
        'logging.channels.keystone-test' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'keystone.log_channel' => 'keystone-test',
    ]);
    User::factory()->count(2)->create();

    (new EndEverySession(operator: 'jane'))->handle();

    $records = Log::channel('keystone-test')->getLogger()->getHandlers()[0]->getRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->context)->toMatchArray(['type' => 'sessions.terminated', 'user_id' => null, 'actor' => 'operator', 'operator' => 'jane', 'reason' => 'keystone.every_account']);
    $this->assertDatabaseCount('user_security_events', 0);
});

it('records nothing when a transaction around it rolls back', function () {
    Event::fake([SecurityEventRecorded::class]);
    User::factory()->create();

    DB::beginTransaction();
    (new EndEverySession)->handle();
    DB::rollBack();

    Event::assertNotDispatched(SecurityEventRecorded::class);
});
