<?php

// Fault injection through the fake type: only core's fake races another change while it verifies, so no AppTest can.

use ClaudioDekker\Keystone\Accounts\CredentialEpoch;
use ClaudioDekker\Keystone\Accounts\Suspension;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['keystone.require_recovery_codes' => false, 'keystone.require_second_factor' => false]);

    Route::middleware(['web', 'auth', 'sudo'])->get('/gated', fn () => response()->noContent());

    $this->ada = User::factory()->create();
    UserEmail::factory()->for($this->ada, 'user')->verified()->create(['address' => 'ada@example.com']);
    $this->credential = UserCredential::factory()->for($this->ada, 'user')->create(['type' => 'form', 'secret' => 'correct horse']);
    $this->type = app(CredentialTypes::class)->find('form', Surface::SIGN_IN);

    $this->post(route('login.submit', 'form'), ['identifier' => 'ada@example.com', 'secret' => 'correct horse']);

    Auth::forgetGuards();
    $this->travel(config('keystone.sudo.lifetime_seconds'))->seconds();
    $this->get('/gated');
    Auth::forgetGuards();
});

it('refuses an answer whose sudo-in-progress ran out while it was checked, writing nothing', function () {
    $this->type->advanceNextProof('advanced horse');
    $this->type->duringNextVerify(fn () => $this->travel(15)->minutes());

    $response = $this->post(route('sudo.submit', 'form'), ['secret' => 'correct horse']);

    $response->assertRedirect('/gated');
    expect($this->credential->fresh()->secret)->toBe('correct horse')
        ->and(UserSecurityEvent::query()->where('type', SecurityEventType::SUDO_GRANTED)->count())->toBe(1);
});

it("ends with no grant when the account's credential epoch moved while the answer was checked", function () {
    $this->type->duringNextVerify(fn () => CredentialEpoch::move($this->ada->getKey()));

    $this->post(route('sudo.submit', 'form'), ['secret' => 'correct horse']);

    expect(UserSecurityEvent::query()->where('type', SecurityEventType::SUDO_GRANTED)->count())->toBe(1);
});

it('refuses an account suspended while the answer was checked as barred', function () {
    $this->type->duringNextVerify(fn () => Suspension::suspend($this->ada->getKey()));

    $this->post(route('sudo.submit', 'form'), ['secret' => 'correct horse']);

    $failed = UserSecurityEvent::query()->latest('id')->first();

    expect($failed->type)->toBe(SecurityEventType::SUDO_FAILED)
        ->and($failed->reason)->toBe('keystone.barred')
        ->and(UserSecurityEvent::query()->where('type', SecurityEventType::SUDO_GRANTED)->count())->toBe(1);
});

it('refuses an account that came to owe enrollment while the answer was checked as barred', function () {
    $this->type->duringNextVerify(fn () => config(['keystone.require_second_factor' => true]));

    $this->post(route('sudo.submit', 'form'), ['secret' => 'correct horse']);

    $failed = UserSecurityEvent::query()->latest('id')->first();

    expect($failed->type)->toBe(SecurityEventType::SUDO_FAILED)
        ->and($failed->reason)->toBe('keystone.barred')
        ->and(UserSecurityEvent::query()->where('type', SecurityEventType::SUDO_GRANTED)->count())->toBe(1);
});
