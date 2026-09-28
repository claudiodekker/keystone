<?php

const PACKAGE_NAMESPACES = [
    'ClaudioDekker\Keystone\Password',
    'ClaudioDekker\Keystone\WebAuthn',
    'ClaudioDekker\Keystone\Totp',
    'ClaudioDekker\Keystone\MagicLink',
    'ClaudioDekker\Keystone\OAuth',
    'ClaudioDekker\Keystone\InertiaVue',
    'ClaudioDekker\Keystone\Blade',
];

const TEST_NAMESPACES = [
    'ClaudioDekker\Keystone\Tests',
    'ClaudioDekker\Keystone\Password\Tests',
    'ClaudioDekker\Keystone\WebAuthn\Tests',
    'ClaudioDekker\Keystone\Totp\Tests',
    'ClaudioDekker\Keystone\MagicLink\Tests',
    'ClaudioDekker\Keystone\OAuth\Tests',
    'ClaudioDekker\Keystone\InertiaVue\Tests',
    'ClaudioDekker\Keystone\Blade\Tests',
];

arch('debugging functions are never left in')
    ->expect(['dd', 'dump', 'ray'])
    ->not->toBeUsed();

arch('src never depends on tests')
    ->expect([...TEST_NAMESPACES, 'Tests'])
    ->toOnlyBeUsedIn([...TEST_NAMESPACES, 'Tests']);

arch('core never depends on a method or adapter package')
    ->expect(PACKAGE_NAMESPACES)
    ->toOnlyBeUsedIn([...PACKAGE_NAMESPACES, 'Tests']);
