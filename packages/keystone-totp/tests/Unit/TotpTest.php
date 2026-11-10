<?php

use ClaudioDekker\Keystone\Totp\Totp;

it('makes the codes of RFC 6238 Appendix B for SHA-1, as every authenticator app does', function (int $timestamp, string $code) {
    expect(Totp::code('12345678901234567890', Totp::stepAt($timestamp)))->toBe($code);
})->with([
    '59' => [59, '287082'],
    '1111111109' => [1111111109, '081804'],
    '1111111111' => [1111111111, '050471'],
    '1234567890' => [1234567890, '005924'],
    '2000000000' => [2000000000, '279037'],
    '20000000000' => [20000000000, '353130'],
]);
