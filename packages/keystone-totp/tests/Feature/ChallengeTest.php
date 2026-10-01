<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\Totp\AppTests\Support\TotpTypeSupport;
use ClaudioDekker\Keystone\Totp\Totp;
use ClaudioDekker\Keystone\Totp\TotpSecret;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

pest()->extend(AppTestCase::class);

const TOTP_KEY = '12345678901234567890';

beforeEach(function () {
    $this->freezeSecond();
});

function holdForTotp(AppTestCase $test, string $key = TOTP_KEY, ?int $lastStep = null): Model&KeystoneUser
{
    $account = $test->createChallengedAccount(new TotpTypeSupport);

    DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'totp')->update([
        'secret' => Crypt::encryptString((new TotpSecret($key, $lastStep))->toStored()),
    ]);

    $test->passFirstFactor();

    return $account;
}

function answerTotp(AppTestCase $test, string $code)
{
    return $test->post(route('login.challenge.submit', ['type' => 'totp']), ['code' => $code]);
}

function totpCode(int $offset, string $key = TOTP_KEY): string
{
    $totp = new Totp;

    return $totp->code($key, $totp->stepAt(now()->getTimestamp()) + $offset);
}

function storedTotpStep(Model&KeystoneUser $account): ?int
{
    $stored = DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'totp')->value('secret');

    return TotpSecret::fromStored(Crypt::decryptString($stored))->lastStep;
}

it('accepts a code from the step before, the current step or the step after', function (int $offset) {
    $account = holdForTotp($this);

    answerTotp($this, totpCode($offset))->assertRedirect('/');

    $this->assertAuthenticatedAs($account);
})->with(['the step before' => -1, 'the current step' => 0, 'the step after' => 1]);

it('refuses a code from two steps away', function (int $offset) {
    holdForTotp($this);

    answerTotp($this, totpCode($offset))->assertSessionHasErrors(['totp' => __('keystone::messages.invalid_credential')]);

    $this->assertGuest();
})->with(['two steps before' => -2, 'two steps after' => 2]);

it('accepts only the current step\'s code with a window of 0', function (int $offset, bool $accepted) {
    config(['keystone-totp.window_steps' => 0]);
    holdForTotp($this);

    answerTotp($this, totpCode($offset));

    expect(auth()->check())->toBe($accepted);
})->with([
    'the step before' => [-1, false],
    'the current step' => [0, true],
    'the step after' => [1, false],
]);

it('accepts a code from the edge of a wide window', function () {
    config(['keystone-totp.window_steps' => 10]);
    $account = holdForTotp($this);

    answerTotp($this, totpCode(10))->assertRedirect('/');

    $this->assertAuthenticatedAs($account);
});

it('accepts a code typed with spaces', function () {
    $account = holdForTotp($this);
    $code = totpCode(0);

    answerTotp($this, substr($code, 0, 3).' '.substr($code, 3))->assertRedirect('/');

    $this->assertAuthenticatedAs($account);
});

it('stores the step of the accepted code as the last one accepted', function () {
    $account = holdForTotp($this);

    answerTotp($this, totpCode(1));

    expect(storedTotpStep($account))->toBe((new Totp)->stepAt(now()->getTimestamp()) + 1);
});

it('refuses a code already used, recording the replay', function () {
    $account = holdForTotp($this);
    $code = totpCode(0);
    answerTotp($this, $code);
    $this->post(route('logout'));
    $this->passFirstFactor();

    answerTotp($this, $code)->assertSessionHasErrors('totp');

    $this->assertGuest();
    $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'reason' => 'totp.replayed']);
});

it('refuses a code from a step before the last one accepted', function () {
    $now = (new Totp)->stepAt(now()->getTimestamp());
    holdForTotp($this, lastStep: $now);

    answerTotp($this, totpCode(-1))->assertSessionHasErrors('totp');

    $this->assertGuest();
});

it('accepts a code from a step after the last one accepted', function () {
    $now = (new Totp)->stepAt(now()->getTimestamp());
    $account = holdForTotp($this, lastStep: $now - 1);

    answerTotp($this, totpCode(0))->assertRedirect('/');

    $this->assertAuthenticatedAs($account);
});

it('accepts a code of an imported 80-bit key', function () {
    $key = random_bytes(10);
    $account = holdForTotp($this, key: $key);

    answerTotp($this, totpCode(0, $key))->assertRedirect('/');

    $this->assertAuthenticatedAs($account);
});

it('refuses a code when the stored secret is malformed, and reports it', function () {
    Exceptions::fake();
    $account = holdForTotp($this);
    DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'totp')->update(['secret' => Crypt::encryptString('not json')]);

    answerTotp($this, totpCode(0))->assertSessionHasErrors('totp');

    $this->assertGuest();
    Exceptions::assertReported(JsonException::class);
});
