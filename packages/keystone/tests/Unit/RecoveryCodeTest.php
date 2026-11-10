<?php

use ClaudioDekker\Keystone\RecoveryCodes\RecoveryCode;

it('generates a set of 8 codes', function () {
    expect(RecoveryCode::generateSet())->toHaveCount(8);
});

it('generates a code of 26 characters from A to Z and 0 to 9', function () {
    $code = RecoveryCode::generate();

    expect($code->value)->toMatch('/\A[A-Z0-9]{26}\z/');
});

it('shows a code in dash-separated blocks of 5', function () {
    $code = RecoveryCode::fromInput('7KQ2MXW9PD4HNRTB3ZCF6JVGL8');

    expect($code->format())->toBe('7KQ2M-XW9PD-4HNRT-B3ZCF-6JVGL-8');
});

it('stores a code under the SHA-256 digest of its characters in upper case, which imported and saved codes are written as', function () {
    $code = RecoveryCode::fromInput('7kq2m-xw9pd-4hnrt-b3zcf-6jvgl-8');

    expect($code->digest())->toBe('3078b99ebfb744f98e78627006d018fbf1cb52a3db2cae9bc1ae2e062cfd5054');
});

it('draws every character of the alphabet across many codes', function () {
    $drawn = collect(range(1, 1000))
        ->map(fn () => RecoveryCode::generate()->value)
        ->flatMap(fn (string $value) => str_split($value))
        ->unique()
        ->sort()
        ->implode('');

    expect($drawn)->toBe('0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ');
});

it('generates distinct codes', function () {
    $values = collect(range(1, 1000))->map(fn () => RecoveryCode::generate()->value);

    expect($values->unique())->toHaveCount(1000);
});
