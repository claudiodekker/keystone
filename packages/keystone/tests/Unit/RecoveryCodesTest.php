<?php

use ClaudioDekker\Keystone\RecoveryCodes;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;

it('makes a set of 8 codes of 26 characters from A to Z and 0 to 9, shown in dash-separated blocks of 5', function () {
    $user = User::factory()->create();

    $codes = (new RecoveryCodes($user))->generate();

    expect($codes)->toHaveCount(8)->each->toMatch('/^[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]$/');
    expect(array_unique($codes))->toHaveCount(8);
});

it('stores only the SHA-256 digest of each code, without its dashes', function () {
    $user = User::factory()->create();
    $codes = (new RecoveryCodes($user))->generate();

    (new RecoveryCodes($user))->replace($user->getKey(), $codes);

    $digests = array_map(fn (string $code) => hash('sha256', str_replace('-', '', $code)), $codes);
    expect(DB::table('user_recovery_codes')->where('user_id', $user->getKey())->pluck('code_hash')->all())->toEqualCanonicalizing($digests);
});

it('replaces the account\'s earlier codes, leaving other accounts\' alone', function () {
    [$jane, $john] = User::factory()->count(2)->create();
    $codes = new RecoveryCodes($jane);
    $codes->replace($jane->getKey(), ['JANE-OLD']);
    $codes->replace($john->getKey(), ['JOHN-OLD']);

    $codes->replace($jane->getKey(), ['JANE-NEW-1', 'JANE-NEW-2']);

    expect($codes->find($jane->getKey(), 'JANE-OLD'))->toBeNull()
        ->and($codes->find($jane->getKey(), 'JANE-NEW-1'))->not->toBeNull()
        ->and($codes->remaining($jane->getKey()))->toBe(2)
        ->and($codes->find($john->getKey(), 'JOHN-OLD'))->not->toBeNull();
});

it('spends a code once, so a second spend deletes nothing', function () {
    $user = User::factory()->create();
    $codes = new RecoveryCodes($user);
    $codes->replace($user->getKey(), ['ABCDE-FGHIJ']);
    $id = $codes->find($user->getKey(), 'ABCDE-FGHIJ');

    $first = $codes->spend($id);
    $second = $codes->spend($id);

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($codes->remaining($user->getKey()))->toBe(0);
});
