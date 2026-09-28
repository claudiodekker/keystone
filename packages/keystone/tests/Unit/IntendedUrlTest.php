<?php

use ClaudioDekker\Keystone\IntendedUrl;

test('a same-origin intended URL is kept as a relative path', function (?string $url, string $path) {
    expect(IntendedUrl::sanitize($url, 'https://app.example'))->toBe($path);
})->with([
    'nothing intended' => [null, '/'],
    'relative path' => ['/dashboard', '/dashboard'],
    'relative path with query' => ['/dashboard?tab=1', '/dashboard?tab=1'],
    'same-origin absolute URL' => ['https://app.example/dashboard?tab=1', '/dashboard?tab=1'],
    'same-origin root' => ['https://app.example', '/'],
    'other host' => ['https://evil.example/dashboard', '/'],
    'other scheme' => ['http://app.example/dashboard', '/'],
    'other port' => ['https://app.example:8443/dashboard', '/'],
    'protocol-relative' => ['//evil.example/dashboard', '/'],
    'encoded protocol-relative' => ['/%2Fevil.example', '/'],
    'backslash' => ['/\\evil.example', '/'],
    'encoded backslash' => ['/%5Cevil.example', '/'],
    'control character' => ["/dash\tboard", '/'],
    'encoded control character' => ['/dash%0Aboard', '/'],
    'no leading slash' => ['dashboard', '/'],
    'javascript scheme' => ['javascript:alert(1)', '/'],
    'userinfo' => ['https://app.example@evil.example/', '/'],
]);
