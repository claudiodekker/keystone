<?php

// Fault injection and pinned formats of the recorder, which no app can change.

use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserSecurityEvent;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\Models\User;

it('stores a reason prefixed by the credential type or by keystone', function (?string $credentialType, string $reason) {
    $user = User::factory()->create();
    $type = $credentialType === null ? null : Keystone::credentialTypes()->find($credentialType, Surface::SIGN_IN);

    $this->app->make(Recorder::class)->record(SecurityEventType::PROOF_REJECTED, account: $user, credentialType: $type, reason: $reason);

    expect(UserSecurityEvent::query()->where('user_id', $user->getKey())->sole()->reason)->toBe($reason);
})->with([
    'the credential type' => ['form', 'form.counter_regressed'],
    'keystone, with a credential type' => ['form', 'keystone.superseded'],
    'keystone, without a credential type' => [null, 'keystone.every_account'],
    'the longest code allowed' => ['form', 'form.'.str_repeat('a', 59)],
]);

it('stores anything else as the invalid reason of the credential type, or of keystone', function (?string $credentialType, string $reason, string $stored) {
    $user = User::factory()->create();
    $type = $credentialType === null ? null : Keystone::credentialTypes()->find($credentialType, Surface::SIGN_IN);

    $this->app->make(Recorder::class)->record(SecurityEventType::PROOF_REJECTED, account: $user, credentialType: $type, reason: $reason);

    expect(UserSecurityEvent::query()->where('user_id', $user->getKey())->sole()->reason)->toBe($stored);
})->with([
    'another prefix' => ['form', 'pin.counter_regressed', 'form.invalid_reason'],
    'no prefix' => ['form', 'counter_regressed', 'form.invalid_reason'],
    'the type prefix without a credential type' => [null, 'form.counter_regressed', 'keystone.invalid_reason'],
    'an uppercase letter' => ['form', 'form.Counter', 'form.invalid_reason'],
    'a space' => ['form', 'form.counter regressed', 'form.invalid_reason'],
    'a hyphen in the code' => ['form', 'form.counter-regressed', 'form.invalid_reason'],
    'a code longer than 64 characters' => ['form', 'form.'.str_repeat('a', 60), 'form.invalid_reason'],
    'an empty code' => ['form', 'form.', 'form.invalid_reason'],
    'a trailing newline' => ['form', "form.counter\n", 'form.invalid_reason'],
]);
