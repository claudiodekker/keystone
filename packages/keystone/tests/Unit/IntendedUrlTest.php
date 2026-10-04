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
    'three leading slashes' => ['///evil.example', '/'],
    'four leading slashes' => ['////evil.example', '/'],
    'encoded protocol-relative' => ['/%2Fevil.example', '/'],
    'backslash' => ['/\\evil.example', '/'],
    'encoded backslash' => ['/%5Cevil.example', '/'],
    'control character' => ["/dash\tboard", '/'],
    'encoded control character' => ['/dash%0Aboard', '/'],
    'no leading slash' => ['dashboard', '/'],
    'javascript scheme' => ['javascript:alert(1)', '/'],
    'userinfo' => ['https://app.example@evil.example/', '/'],
    'mixed-case scheme and host' => ['HTTPS://App.Example/dashboard?tab=1', '/dashboard?tab=1'],
    'mixed-case scheme and other host' => ['HTTPS://Evil.Example/dashboard', '/'],
    'fragment on a relative path' => ['/dashboard#section', '/dashboard#section'],
    'fragment on a same-origin URL' => ['https://app.example/dashboard#section', '/dashboard#section'],
    'fragment naming the app origin on another host' => ['https://evil.example/#https://app.example', '/'],
    'fragment holding a protocol-relative URL' => ['/#//evil.example', '/#//evil.example'],
    'explicit default port, which is not compared as the same origin' => ['https://app.example:443/dashboard', '/'],
    'default port of another scheme' => ['https://app.example:80/dashboard', '/'],
]);

test('an app URL is compared as written, ignoring only the case of its scheme and host', function (string $appUrl, string $url, string $path) {
    expect(IntendedUrl::sanitize($url, $appUrl))->toBe($path);
})->with([
    'mixed-case app URL' => ['HTTPS://App.Example', 'https://app.example/dashboard', '/dashboard'],
    'app URL with a path' => ['https://app.example/app/', 'https://app.example/dashboard', '/dashboard'],
    'app URL with a port' => ['https://app.example:8443', 'https://app.example:8443/dashboard', '/dashboard'],
    'app URL with an explicit default port' => ['https://app.example:443', 'https://app.example/dashboard', '/'],
]);
