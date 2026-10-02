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

it('refuses a code the new key doesn\'t make, storing nothing', function () {
    enrollingTotpKey($this);

    $response = $this->post(route('login.enrollment.submit', ['type' => 'totp']), ['code' => '000000x']);

    $response->assertRedirectToRoute('login.enrollment.start', ['type' => 'totp'])->assertSessionHasErrors(['totp']);
    $this->assertDatabaseMissing('user_credentials', ['type' => 'totp']);
});
