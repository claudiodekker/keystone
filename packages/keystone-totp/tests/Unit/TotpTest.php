<?php

use ClaudioDekker\Keystone\Totp\Totp;

it('matches the SHA-1 vectors of RFC 6238 Appendix B', function (int $timestamp, string $code) {
    $totp = new Totp;

    expect($totp->code('12345678901234567890', $totp->stepAt($timestamp)))->toBe($code);
})->with([
    '59' => [59, '287082'],
    '1111111109' => [1111111109, '081804'],
    '1111111111' => [1111111111, '050471'],
    '1234567890' => [1234567890, '005924'],
    '2000000000' => [2000000000, '279037'],
    '20000000000' => [20000000000, '353130'],
]);

it('steps every 30 seconds', function (int $timestamp, int $step) {
    expect((new Totp)->stepAt($timestamp))->toBe($step);
})->with([
    'the first second' => [0, 0],
    'the last second of a step' => [29, 0],
    'the first second of the next' => [30, 1],
]);
