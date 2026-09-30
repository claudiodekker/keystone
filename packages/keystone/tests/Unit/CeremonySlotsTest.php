<?php

use ClaudioDekker\Keystone\CeremonySlots;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\PendingStage;
use ClaudioDekker\Keystone\Tests\Fixtures\User;

beforeEach(function () {
    $this->freezeSecond();
});

it('keeps a slot until its own cap ends', function () {
    $slots = Keystone::guard()->slots();
    $slots->put('form', 'challenge', 'bytes', capSeconds: 300);

    $this->travel(299)->seconds();
    expect($slots->get('form', 'challenge'))->toBe('bytes');

    $this->travel(1)->second();
    expect($slots->get('form', 'challenge'))->toBeNull();
});

it('ends a slot with the pending sign-in that opened it, when that comes first', function () {
    Keystone::guard()->hold(User::factory()->create(), 'form', PendingStage::CHALLENGE, '/');
    $this->travel(14)->minutes();
    $slots = Keystone::guard()->slots();
    $slots->put('form', 'challenge', 'bytes', capSeconds: 300);

    $this->travel(59)->seconds();
    expect($slots->get('form', 'challenge'))->toBe('bytes');

    $this->travel(1)->second();
    expect($slots->get('form', 'challenge'))->toBeNull();
});

it('ends a slot with the sign-in\'s absolute lifetime, when that comes first', function () {
    config(['keystone.session.absolute_lifetime_seconds' => 120]);
    Keystone::guard()->signIn(User::factory()->create());
    $slots = Keystone::guard()->slots();
    $slots->put('form', 'sudo', 'bytes', capSeconds: 300);

    $this->travel(119)->seconds();
    expect($slots->get('form', 'sudo'))->toBe('bytes');

    $this->travel(1)->second();
    expect($slots->get('form', 'sudo'))->toBeNull();
});

it('keys slots by method and purpose', function () {
    $slots = Keystone::guard()->slots();
    $slots->put('form', 'challenge', 'challenge-bytes', capSeconds: 300);
    $slots->put('form', 'sudo', 'sudo-bytes', capSeconds: 300);
    $slots->put('other', 'challenge', 'other-bytes', capSeconds: 300);

    $slots->forget('form', 'challenge');

    expect($slots->get('form', 'challenge'))->toBeNull()
        ->and($slots->get('form', 'sudo'))->toBe('sudo-bytes')
        ->and($slots->get('other', 'challenge'))->toBe('other-bytes');
});

it('keeps no slot value in clear in the session', function () {
    Keystone::guard()->slots()->put('form', 'challenge', 'challenge-bytes', capSeconds: 300);

    expect(serialize(session()->get(CeremonySlots::SESSION_KEY)))->not->toContain('challenge-bytes');
});

it('closes every slot when the auth level changes', function (Closure $change) {
    Keystone::guard()->slots()->put('form', 'challenge', 'bytes', capSeconds: 300);

    $change();

    expect(Keystone::guard()->slots()->get('form', 'challenge'))->toBeNull();
})->with([
    'held' => fn () => fn () => Keystone::guard()->hold(User::factory()->create(), 'form', PendingStage::CHALLENGE, '/'),
    'signed in' => fn () => fn () => Keystone::guard()->signIn(User::factory()->create()),
    'held sign-in dropped' => fn () => fn () => Keystone::guard()->forgetPending(),
]);
