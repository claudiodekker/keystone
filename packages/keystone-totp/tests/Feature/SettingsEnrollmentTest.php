<?php

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\SecurityEventType;
use ClaudioDekker\Keystone\Totp\AppTests\Support\TotpTypeSupport;
use ClaudioDekker\Keystone\Totp\Totp;
use ClaudioDekker\Keystone\Totp\TotpSecret;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use ParagonIE\ConstantTime\Base32;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    $this->freezeSecond();
    config(['keystone.require_second_factor' => false, 'keystone.require_recovery_codes' => false]);
});

function signInWithoutTotp(AppTestCase $test): Model&KeystoneUser
{
    $account = $test->createFirstFactorAccount();
    $test->passFirstFactor();

    return $account;
}

function signInWithTotp(AppTestCase $test): Model&KeystoneUser
{
    $account = $test->createChallengedAccount(new TotpTypeSupport);
    $test->passFirstFactor();
    $test->post(route('login.challenge.submit', ['type' => 'totp']), (new TotpTypeSupport)->validProof(Surface::CHALLENGE));

    return $account;
}

function settingsTotpKey(AppTestCase $test): string
{
    $test->get(route('security.enroll', ['type' => 'totp']));

    return TotpSecret::fromStored(Keystone::guard()->slots()->get('totp', Surface::ENROLLMENT->value)['ceremony'])->key;
}

function settingsTotpCode(string $key, int $offset = 0): string
{
    $totp = new Totp;

    return $totp->code($key, $totp->stepAt(now()->getTimestamp()) + $offset);
}

it('shows a new 160-bit key as Base32, as its otpauth URI and as a QR code of that URI', function () {
    signInWithoutTotp($this);

    $response = $this->get(route('security.enroll', ['type' => 'totp']));

    $key = TotpSecret::fromStored(Keystone::guard()->slots()->get('totp', Surface::ENROLLMENT->value)['ceremony'])->key;
    $page = $response->assertOk()->json('ceremony');
    $svg = (new Writer(new ImageRenderer(new RendererStyle(192), new SvgImageBackEnd)))->writeString($page['uri']);
    expect(strlen($key))->toBe(20)
        ->and($page['key'])->toBe(Base32::encodeUpperUnpadded($key))
        ->and($page['uri'])->toStartWith('otpauth://totp/')->toContain("secret={$page['key']}")
        ->and($page['qr'])->toBe('data:image/svg+xml;base64,'.base64_encode($svg));
});

it('keeps the key and the URI in the session, and never the QR code, so the ceremony fits a cookie session', function () {
    signInWithoutTotp($this);

    $page = $this->get(route('security.enroll', ['type' => 'totp']))->json('ceremony');

    $kept = Keystone::guard()->slots()->get('totp', Surface::ENROLLMENT->value);
    expect(array_keys($page))->toBe(['key', 'uri', 'qr'])
        ->and($kept['page'])->toBe(['key' => $page['key'], 'uri' => $page['uri']])
        ->and(strlen(Crypt::encrypt($kept)))->toBeLessThan(1024);
});

it('shows the same key and QR code on a refresh, and stores neither in the browser', function () {
    signInWithoutTotp($this);

    $first = $this->get(route('security.enroll', ['type' => 'totp']));
    $second = $this->get(route('security.enroll', ['type' => 'totp']));

    expect($second->json('ceremony'))->toBe($first->json('ceremony'));
    $this->assertHardeningFloor($second);
});

it('enrolls the key once a code it makes is typed back, and sends the user to the security page with the enrolled status', function () {
    $account = signInWithoutTotp($this);
    $key = settingsTotpKey($this);
    $now = (new Totp)->stepAt(now()->getTimestamp());

    $response = $this->post(route('security.enroll.submit', ['type' => 'totp']), ['code' => settingsTotpCode($key)]);

    $response->assertRedirectToRoute('security');
    $stored = DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'totp')->sole();
    expect(TotpSecret::fromStored(Crypt::decryptString($stored->secret)))->toEqual(new TotpSecret($key, lastStep: $now));
    $this->assertDatabaseHas('user_security_events', ['type' => 'credential.added', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'totp', 'credential_id' => $stored->id]);
    $this->get(route('security'))->assertJsonPath('status', __('keystone::messages.status.enrolled'));
});

it('moves no epoch for an account\'s first TOTP credential', function () {
    $account = signInWithoutTotp($this);
    $key = settingsTotpKey($this);

    $this->post(route('security.enroll.submit', ['type' => 'totp']), ['code' => settingsTotpCode($key)]);

    $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
    $this->assertAuthenticatedAs($account);
});

it('replaces the TOTP credentials the account holds, disabled ones included, and moves the epoch', function () {
    $account = signInWithTotp($this);
    DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'totp', 'secret' => Crypt::encryptString((new TotpSecret('09876543210987654321', lastStep: null))->toStored()), 'disabled_at' => now()]);
    $key = settingsTotpKey($this);

    $response = $this->post(route('security.enroll.submit', ['type' => 'totp']), ['code' => settingsTotpCode($key)]);

    $response->assertRedirectToRoute('security');
    $stored = DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'totp')->sole();
    expect(TotpSecret::fromStored(Crypt::decryptString($stored->secret))->key)->toBe($key)
        ->and($stored->disabled_at)->toBeNull()
        ->and(SecurityEvent::query()->whereIn('type', ['credential.added', 'credential.removed'])->pluck('type')->all())->toBe([SecurityEventType::CREDENTIAL_ADDED]);
    $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 1]);
    $this->assertAuthenticatedAs($account);
});

it('enrolls a first key once when the same code arrives twice at once, moving no epoch', function () {
    $account = signInWithoutTotp($this);
    $key = settingsTotpKey($this);
    $readByBoth = Keystone::guard()->slots()->get('totp', Surface::ENROLLMENT->value);
    $this->post(route('security.enroll.submit', ['type' => 'totp']), ['code' => settingsTotpCode($key)])->assertRedirectToRoute('security');
    Keystone::guard()->slots()->put('totp', Surface::ENROLLMENT->value, $readByBoth, capSeconds: 900);
    session()->save();

    $response = $this->post(route('security.enroll.submit', ['type' => 'totp']), ['code' => settingsTotpCode($key)]);

    $response->assertRedirectToRoute('security');
    $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
    expect(DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'totp')->count())->toBe(1)
        ->and(SecurityEvent::query()->where('type', 'credential.added')->count())->toBe(1);
});

it('answers the next challenge with the new key only, once it replaced the old one', function () {
    signInWithTotp($this);
    $key = settingsTotpKey($this);
    $this->post(route('security.enroll.submit', ['type' => 'totp']), ['code' => settingsTotpCode($key)]);
    $this->post(route('logout'));
    $this->passFirstFactor();

    $this->post(route('login.challenge.submit', ['type' => 'totp']), (new TotpTypeSupport)->validProofOfEnrolled((new TotpSecret('12345678901234567890', lastStep: null))->toStored()))->assertSessionHasErrors('totp');
    $this->assertGuest();
    $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'flow' => 'challenge', 'reason' => 'totp.mismatch']);
    $this->post(route('login.challenge.submit', ['type' => 'totp']), ['code' => settingsTotpCode($key)])->assertSessionHasErrors('totp');
    $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'flow' => 'challenge', 'reason' => 'totp.replayed']);

    $this->post(route('login.challenge.submit', ['type' => 'totp']), ['code' => settingsTotpCode($key, offset: 1)]);

    $this->assertAuthenticated();
});

it('refuses a wrong code, keeps the key and counts it in the settings flow only', function () {
    config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
    $account = signInWithTotp($this);
    $key = settingsTotpKey($this);

    $response = $this->post(route('security.enroll.submit', ['type' => 'totp']), (new TotpTypeSupport)->rejectedEnrollment((new TotpSecret($key, lastStep: null))->toStored()));

    $response->assertRedirectToRoute('security.enroll', ['type' => 'totp'])->assertSessionHasErrors(['totp' => __('keystone::messages.invalid_credential')]);
    $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'totp', 'reason' => 'totp.mismatch']);
    $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
    expect(settingsTotpKey($this))->toBe($key)
        ->and(DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'totp')->count())->toBe(1);
    $this->post(route('security.enroll.submit', ['type' => 'totp']), ['code' => settingsTotpCode($key)])->assertTooManyRequests();
    $this->post(route('logout'));
    $this->passFirstFactor();
    $this->post(route('login.challenge.submit', ['type' => 'totp']), (new TotpTypeSupport)->validProofOfEnrolled((new TotpSecret('12345678901234567890', lastStep: null))->toStored()));
    $this->assertAuthenticatedAs($account);
});

it('makes a new key once the enrollment is cancelled', function () {
    signInWithoutTotp($this);
    $key = settingsTotpKey($this);

    $this->delete(route('security.enroll.cancel', ['type' => 'totp']))->assertRedirectToRoute('security');

    expect(settingsTotpKey($this))->not->toBe($key);
});
