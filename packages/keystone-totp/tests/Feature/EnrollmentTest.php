<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Totp\Totp;
use ClaudioDekker\Keystone\Totp\TotpSecret;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use ParagonIE\ConstantTime\Base32;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->freezeSecond();
    config(['keystone.require_second_factor' => true, 'keystone.require_recovery_codes' => false]);
});

function enrollingTotpKey(AppTestCase $test): string
{
    $test->createFirstFactorAccount();
    $test->passFirstFactor();

    $page = $test->get(route('login.enrollment.start', ['type' => 'totp']))->json('ceremony');
    $ceremony = Keystone::guard()->slots()->get('totp', Surface::ENROLLMENT->value)['ceremony'];
    $secret = TotpSecret::fromStored($ceremony);

    expect($page['key'])->toBe(Base32::encodeUpperUnpadded($secret->key));

    return $secret->key;
}

it('enrolls the key its form shows once a code it makes is typed back, signing in', function () {
    $key = enrollingTotpKey($this);
    $totp = new Totp;
    $now = $totp->stepAt(now()->getTimestamp());

    $response = $this->post(route('login.enrollment.submit', ['type' => 'totp']), ['code' => $totp->code($key, $now)]);

    $response->assertRedirect('/');
    $stored = DB::table('user_credentials')->where('type', 'totp')->sole();
    expect(TotpSecret::fromStored(Crypt::decryptString($stored->secret)))->toEqual(new TotpSecret($key, lastStep: $now));
});

it('refuses the enrolling code at the next challenge as a replay, and accepts the step after', function () {
    $key = enrollingTotpKey($this);
    $totp = new Totp;
    $now = $totp->stepAt(now()->getTimestamp());
    $this->post(route('login.enrollment.submit', ['type' => 'totp']), ['code' => $totp->code($key, $now)]);
    $this->post(route('logout'));
    $this->passFirstFactor()->assertRedirectToRoute('login.challenge');

    $this->post(route('login.challenge.submit', ['type' => 'totp']), ['code' => $totp->code($key, $now)])->assertSessionHasErrors('totp');

    $this->assertGuest();
    $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'flow' => 'challenge', 'reason' => 'totp.replayed']);
    $this->post(route('login.challenge.submit', ['type' => 'totp']), ['code' => $totp->code($key, $now + 1)])->assertRedirect('/');
    $this->assertAuthenticated();
});

it('refuses a code the new key doesn\'t make, storing nothing', function () {
    enrollingTotpKey($this);

    $response = $this->post(route('login.enrollment.submit', ['type' => 'totp']), ['code' => '000000x']);

    $response->assertRedirectToRoute('login.enrollment.start', ['type' => 'totp'])->assertSessionHasErrors(['totp']);
    $this->assertDatabaseMissing('user_credentials', ['type' => 'totp']);
});

it('shows the new key as a QR code on the form a sign-in is held at', function () {
    $this->createFirstFactorAccount();
    $this->passFirstFactor();

    $page = $this->get(route('login.enrollment.start', ['type' => 'totp']))->json('ceremony');

    expect($page['qr'])->toStartWith('data:image/svg+xml;base64,')
        ->and(base64_decode(substr($page['qr'], 26)))->toContain('<svg');
});

it('keeps the key and the URI of a held sign-in\'s enrollment in the session, and never the QR code, and shows the same QR code on a refresh', function () {
    $this->createFirstFactorAccount();
    $this->passFirstFactor();

    $page = $this->get(route('login.enrollment.start', ['type' => 'totp']))->json('ceremony');
    $again = $this->get(route('login.enrollment.start', ['type' => 'totp']))->json('ceremony');

    $kept = Keystone::guard()->slots()->get('totp', Surface::ENROLLMENT->value);
    expect($kept['page'])->toBe(['key' => $page['key'], 'uri' => $page['uri']])
        ->and(strlen(Crypt::encrypt($kept)))->toBeLessThan(1024)
        ->and($again)->toBe($page);
});

it('replaces nothing when a held sign-in enrolls: a disabled TOTP credential stays beside the new one', function () {
    $account = $this->createFirstFactorAccount();
    $disabled = DB::table('user_credentials')->insertGetId(['user_id' => $account->getKey(), 'type' => 'totp', 'secret' => Crypt::encryptString((new TotpSecret('09876543210987654321', lastStep: null))->toStored()), 'disabled_at' => now()]);
    $this->passFirstFactor();
    $page = $this->get(route('login.enrollment.start', ['type' => 'totp']))->json('ceremony');
    $totp = new Totp;

    $this->post(route('login.enrollment.submit', ['type' => 'totp']), ['code' => $totp->code(Base32::decodeUpper($page['key']), $totp->stepAt(now()->getTimestamp()))])->assertRedirect('/');

    $this->assertDatabaseHas('user_credentials', ['id' => $disabled]);
    $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
    expect(DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'totp')->count())->toBe(2);
});
