<?php

use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;

pest()->extend(StubsTestCase::class);

test('the stub routes are grouped under auth/', function () {
    expect(route('login', absolute: false))->toBe('/auth/login')
        ->and(route('login.submit', ['type' => 'password'], absolute: false))->toBe('/auth/login/password')
        ->and(route('logout', absolute: false))->toBe('/auth/logout');
});
