<?php

// Storage shape: what sits in the credentials table at rest, which no app can see through a page.

use ClaudioDekker\Keystone\Models\UserCredential;
use Illuminate\Support\Facades\Crypt;

it('keeps the secret encrypted at rest', function () {
    $credential = UserCredential::factory()->create(['secret' => 'correct horse']);

    $stored = UserCredential::query()->toBase()->where('id', $credential->getKey())->value('secret');

    expect($stored)->not->toBe('correct horse')
        ->and(Crypt::decryptString($stored))->toBe('correct horse');
});

it('leaves the secret out of the array form', function () {
    $credential = UserCredential::factory()->create(['secret' => 'correct horse']);

    expect($credential->toArray())->not->toHaveKey('secret');
});
