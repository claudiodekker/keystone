<?php

namespace ClaudioDekker\Keystone\Tests\Unit;

use ClaudioDekker\Keystone\Addresses;
use ClaudioDekker\Keystone\Tests\Fixtures\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config([
        'auth.guards.web.driver' => 'keystone',
        'auth.providers.users.model' => User::class,
    ]);
});

function holdAddress(User $user, string $address, bool $verified = true): void
{
    DB::table('user_emails')->insert([
        'user_id' => $user->getKey(),
        'address' => $address,
        'verified_at' => $verified ? now() : null,
        'is_primary' => false,
    ]);
}

describe('normalize', function () {
    test('an address is normalized', function (string $address, string $normalized) {
        expect(Addresses::normalize($address))->toBe($normalized);
    })->with([
        'trimmed' => ["  jane@example.com \n", 'jane@example.com'],
        'lowercased as a whole' => ['Jane.Doe@Example.COM', 'jane.doe@example.com'],
        'NFC composed' => ["rene\u{0301}@example.com", 'rené@example.com'],
        'accents kept' => ['RENÉ@example.com', 'rené@example.com'],
        'domain as punycode' => ['jane@Bücher.example', 'jane@xn--bcher-kva.example'],
        'split on the last @' => ['"a@b"@example.com', '"a@b"@example.com'],
        'no @' => [' Jane ', 'jane'],
        'domain punycode cannot encode' => ['Jane@'.str_repeat('a', 64).'.example', 'jane@'.str_repeat('a', 64).'.example'],
        'invalid UTF-8' => ["Jane\xFF@example.com", 'jane?@example.com'],
    ]);
});

describe('resolve', function () {
    it('resolves an address one active account holds verified', function () {
        $user = User::factory()->create();
        holdAddress($user, 'jane@example.com');

        expect((new Addresses(new User))->resolve('JANE@example.com '))->toBe($user->getKey());
    });

    it('resolves an unverified address while its account holds no verified one', function () {
        $user = User::factory()->create();
        holdAddress($user, 'jane@example.com', verified: false);

        expect((new Addresses(new User))->resolve('jane@example.com'))->toBe($user->getKey());
    });

    it('does not resolve an unverified address of an account that holds a verified one', function () {
        $user = User::factory()->create();
        holdAddress($user, 'jane@example.com', verified: false);
        holdAddress($user, 'jane@work.example');

        expect((new Addresses(new User))->resolve('jane@example.com'))->toBeNull();
    });

    it('does not resolve an address two accounts count as verified', function () {
        [$jane, $john] = User::factory()->count(2)->create();
        holdAddress($jane, 'shared@example.com', verified: false);
        holdAddress($john, 'shared@example.com', verified: false);

        expect((new Addresses(new User))->resolve('shared@example.com'))->toBeNull();
    });

    it('resolves the one account that counts an address as verified when others hold it unverified', function () {
        [$jane, $john] = User::factory()->count(2)->create();
        holdAddress($jane, 'jane@example.com');
        holdAddress($john, 'jane@example.com', verified: false);
        holdAddress($john, 'john@example.com');

        expect((new Addresses(new User))->resolve('jane@example.com'))->toBe($jane->getKey());
    });

    it('does not resolve an address nobody holds', function () {
        holdAddress(User::factory()->create(), 'jane@example.com');

        expect((new Addresses(new User))->resolve('john@example.com'))->toBeNull();
    });

    it('does not resolve an address of a disabled account', function (string $column) {
        $user = User::factory()->create();
        holdAddress($user, 'jane@example.com');
        DB::table('users')->where('id', $user->getKey())->update([$column => now()]);

        expect((new Addresses(new User))->resolve('jane@example.com'))->toBeNull();
    })->with(['deleted_at', 'invalidated_at']);

    it('lets a disabled account\'s hold yield to an active one', function () {
        [$jane, $john] = User::factory()->count(2)->create();
        holdAddress($jane, 'shared@example.com');
        holdAddress($john, 'shared@example.com');
        DB::table('users')->where('id', $jane->getKey())->update(['deleted_at' => now()]);

        expect((new Addresses(new User))->resolve('shared@example.com'))->toBe($john->getKey());
    });

    it('still resolves the address of a suspended account', function () {
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->getKey())->update(['suspended_at' => now()]);
        holdAddress($user, 'jane@example.com');

        expect((new Addresses(new User))->resolve('jane@example.com'))->toBe($user->getKey());
    });
});
