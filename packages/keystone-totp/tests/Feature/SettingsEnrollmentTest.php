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

it('shows a new 160-bit key as Base32, as its otpauth URI and as a QR code of that URI', function () {
    signInWithoutTotp($this);

    $response = $this->get(route('security.enroll', ['type' => 'totp']));

    $key = TotpSecret::fromStored($this->enrollmentCeremony('totp'))->key;
    $page = $response->assertOk()->json('ceremony');
    $svg = (new Writer(new ImageRenderer(new RendererStyle(192), new SvgImageBackEnd)))->writeString($page['uri']);
    expect(strlen($key))->toBe(20)
        ->and($page['key'])->toBe(Base32::encodeUpperUnpadded($key))
        ->and($page['uri'])->toStartWith('otpauth://totp/')->toContain("secret={$page['key']}")
        ->and($page['qr'])->toBe('data:image/svg+xml;base64,'.base64_encode($svg))
        ->and(base64_decode(substr($page['qr'], strlen('data:image/svg+xml;base64,'))))->not->toContain($page['key']);
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

it('enrolls the key once a code it makes is typed back, and sends the user to the security page', function () {
    $account = signInWithoutTotp($this);
    $this->get(route('security.enroll', ['type' => 'totp']));
    $key = TotpSecret::fromStored($this->enrollmentCeremony('totp'))->key;
    $now = (new Totp)->stepAt(now()->getTimestamp());

    $response = $this->post(route('security.enroll.submit', ['type' => 'totp']), (new TotpTypeSupport)->validEnrollment($this->enrollmentCeremony('totp')));

    $response->assertRedirectToRoute('security');
    $stored = DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'totp')->sole();
    expect(TotpSecret::fromStored(Crypt::decryptString($stored->secret)))->toEqual(new TotpSecret($key, lastStep: $now));
    $this->assertDatabaseHas('user_security_events', ['type' => 'credential.added', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'totp', 'credential_id' => $stored->id]);
});

it('shows the enrolled status on the security page once the key is enrolled', function () {
    signInWithoutTotp($this);
    $this->get(route('security.enroll', ['type' => 'totp']));
    $this->post(route('security.enroll.submit', ['type' => 'totp']), (new TotpTypeSupport)->validEnrollment($this->enrollmentCeremony('totp')));

    $response = $this->get(route('security'));

    $response->assertJsonPath('status', __('keystone::messages.status.enrolled'));
});

it('moves no epoch for an account\'s first TOTP credential', function () {
    $account = signInWithoutTotp($this);
    $this->get(route('security.enroll', ['type' => 'totp']));

    $this->post(route('security.enroll.submit', ['type' => 'totp']), (new TotpTypeSupport)->validEnrollment($this->enrollmentCeremony('totp')));

    $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
    $this->assertAuthenticatedAs($account);
});

it('replaces the TOTP credentials the account holds, disabled ones included, and moves the epoch', function () {
    $account = signInWithTotp($this);
    DB::table('user_credentials')->insert(['user_id' => $account->getKey(), 'type' => 'totp', 'secret' => Crypt::encryptString((new TotpSecret('09876543210987654321', lastStep: null))->toStored()), 'disabled_at' => now()]);
    $this->get(route('security.enroll', ['type' => 'totp']));
    $key = TotpSecret::fromStored($this->enrollmentCeremony('totp'))->key;

    $response = $this->post(route('security.enroll.submit', ['type' => 'totp']), (new TotpTypeSupport)->validEnrollment($this->enrollmentCeremony('totp')));

    $response->assertRedirectToRoute('security');
    $stored = DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'totp')->sole();
    expect(TotpSecret::fromStored(Crypt::decryptString($stored->secret))->key)->toBe($key)
        ->and($stored->disabled_at)->toBeNull()
        ->and(SecurityEvent::query()->whereIn('type', ['credential.added', 'credential.removed', 'credential.replaced'])->pluck('type')->all())->toBe([SecurityEventType::CREDENTIAL_REPLACED]);
    $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 1]);
    $this->assertAuthenticatedAs($account);
});

it('enrolls a first key once when the same code arrives twice at once, moving no epoch', function () {
    $account = signInWithoutTotp($this);
    $this->get(route('security.enroll', ['type' => 'totp']));
    $readByBoth = Keystone::guard()->slots()->get('totp', Surface::ENROLLMENT->value);
    $code = (new TotpTypeSupport)->validEnrollment($this->enrollmentCeremony('totp'));
    $this->post(route('security.enroll.submit', ['type' => 'totp']), $code)->assertRedirectToRoute('security');
    Keystone::guard()->slots()->put('totp', Surface::ENROLLMENT->value, $readByBoth, capSeconds: 900);
    session()->save();

    $response = $this->post(route('security.enroll.submit', ['type' => 'totp']), $code);

    $response->assertRedirectToRoute('security');
    $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
    expect(DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'totp')->count())->toBe(1)
        ->and(SecurityEvent::query()->where('type', 'credential.added')->count())->toBe(1);
});

it('refuses the old key at the next challenge once a new one replaced it', function () {
    signInWithTotp($this);
    $this->get(route('security.enroll', ['type' => 'totp']));
    $this->post(route('security.enroll.submit', ['type' => 'totp']), (new TotpTypeSupport)->validEnrollment($this->enrollmentCeremony('totp')));
    $this->post(route('logout'));
    $this->passFirstFactor();

    $response = $this->post(route('login.challenge.submit', ['type' => 'totp']), (new TotpTypeSupport)->validProofOfEnrolled((new TotpSecret('12345678901234567890', lastStep: null))->toStored()));

    $response->assertSessionHasErrors('totp');
    $this->assertGuest();
    $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'flow' => 'challenge', 'reason' => 'totp.mismatch']);
});

it('refuses the code that enrolled the new key at the next challenge', function () {
    signInWithTotp($this);
    $this->get(route('security.enroll', ['type' => 'totp']));
    $enrolling = (new TotpTypeSupport)->validEnrollment($this->enrollmentCeremony('totp'));
    $this->post(route('security.enroll.submit', ['type' => 'totp']), $enrolling);
    $this->post(route('logout'));
    $this->passFirstFactor();

    $response = $this->post(route('login.challenge.submit', ['type' => 'totp']), $enrolling);

    $response->assertSessionHasErrors('totp');
    $this->assertGuest();
    $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'flow' => 'challenge', 'reason' => 'totp.replayed']);
});

it('answers the next challenge with the new key once it replaced the old one', function () {
    signInWithTotp($this);
    $this->get(route('security.enroll', ['type' => 'totp']));
    $ceremony = $this->enrollmentCeremony('totp');
    $this->post(route('security.enroll.submit', ['type' => 'totp']), (new TotpTypeSupport)->validEnrollment($ceremony));
    $this->post(route('logout'));
    $this->passFirstFactor();

    $this->post(route('login.challenge.submit', ['type' => 'totp']), (new TotpTypeSupport)->validProofOfEnrolled($ceremony));

    $this->assertAuthenticated();
});

it('refuses a wrong code in the settings flow, leaving the credentials and the epoch as they were', function () {
    $account = signInWithTotp($this);
    $this->get(route('security.enroll', ['type' => 'totp']));

    $response = $this->post(route('security.enroll.submit', ['type' => 'totp']), (new TotpTypeSupport)->rejectedEnrollment($this->enrollmentCeremony('totp')));

    $response->assertRedirectToRoute('security.enroll', ['type' => 'totp'])->assertSessionHasErrors(['totp' => __('keystone::messages.invalid_credential')]);
    $this->assertDatabaseHas('user_security_events', ['type' => 'proof.rejected', 'user_id' => $account->getKey(), 'flow' => 'settings', 'credential_type' => 'totp', 'reason' => 'totp.mismatch']);
    $this->assertDatabaseHas('users', ['id' => $account->getKey(), 'credential_epoch' => 0]);
    expect(DB::table('user_credentials')->where('user_id', $account->getKey())->where('type', 'totp')->count())->toBe(1);
});

it('shows the same key again after a wrong code', function () {
    signInWithTotp($this);
    $this->get(route('security.enroll', ['type' => 'totp']));
    $ceremony = $this->enrollmentCeremony('totp');
    $this->post(route('security.enroll.submit', ['type' => 'totp']), (new TotpTypeSupport)->rejectedEnrollment($ceremony));

    $this->get(route('security.enroll', ['type' => 'totp']));

    expect($this->enrollmentCeremony('totp'))->toBe($ceremony);
});

it('counts a wrong code toward the settings limit', function () {
    config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
    signInWithTotp($this);
    $this->get(route('security.enroll', ['type' => 'totp']));
    $ceremony = $this->enrollmentCeremony('totp');
    $this->post(route('security.enroll.submit', ['type' => 'totp']), (new TotpTypeSupport)->rejectedEnrollment($ceremony));

    $response = $this->post(route('security.enroll.submit', ['type' => 'totp']), (new TotpTypeSupport)->validEnrollment($ceremony));

    $response->assertTooManyRequests();
});

it('leaves the challenge\'s count alone after a wrong code in the settings flow', function () {
    config(['keystone.rate_limits.failed_attempts_per_hour' => 1]);
    $account = signInWithTotp($this);
    $this->get(route('security.enroll', ['type' => 'totp']));
    $this->post(route('security.enroll.submit', ['type' => 'totp']), (new TotpTypeSupport)->rejectedEnrollment($this->enrollmentCeremony('totp')));
    $this->post(route('logout'));
    $this->passFirstFactor();

    $this->post(route('login.challenge.submit', ['type' => 'totp']), (new TotpTypeSupport)->validProofOfEnrolled((new TotpSecret('12345678901234567890', lastStep: null))->toStored()));

    $this->assertAuthenticatedAs($account);
});

it('makes a new key once the enrollment is cancelled', function () {
    signInWithoutTotp($this);
    $this->get(route('security.enroll', ['type' => 'totp']));
    $ceremony = $this->enrollmentCeremony('totp');
    $this->delete(route('security.enroll.cancel', ['type' => 'totp']));

    $this->get(route('security.enroll', ['type' => 'totp']));

    expect(TotpSecret::fromStored($this->enrollmentCeremony('totp'))->key)->not->toBe(TotpSecret::fromStored($ceremony)->key);
});
