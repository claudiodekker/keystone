<?php

use ClaudioDekker\Keystone\Password\DummyHash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;

it('is made once and reused', function () {
    $dummy = new DummyHash(config());

    expect($dummy->get())->toBe($dummy->get());
});

it('is made anew when any part of the hashing config changes', function () {
    $dummy = new DummyHash(config());
    $before = $dummy->get();

    config(['hashing.bcrypt.rounds' => 5]);
    Hash::forgetDrivers();

    expect($dummy->get())->not->toBe($before);
});

it("is made with the app's driver and cost", function (string $driver, string $algorithm) {
    config(['hashing.driver' => $driver, 'hashing.bcrypt.rounds' => 5]);
    Hash::forgetDrivers();

    $info = Hash::info((new DummyHash(config()))->get());

    expect($info['algoName'])->toBe($algorithm)
        ->and(Hash::needsRehash((new DummyHash(config()))->get()))->toBeFalse();
})->with([
    'bcrypt' => ['bcrypt', 'bcrypt'],
    'argon2i' => ['argon', 'argon2i'],
    'argon2id' => ['argon2id', 'argon2id'],
]);

it('is still made when the cache cannot answer', function () {
    Cache::shouldReceive('rememberForever')->once()->andThrow(new RuntimeException('The cache is down.'));

    $dummy = (new DummyHash(config()))->get();

    expect(Hash::info($dummy)['algoName'])->toBe('bcrypt');
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'The cache is down.');
});
