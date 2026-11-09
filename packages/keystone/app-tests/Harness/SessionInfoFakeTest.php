<?php

use ClaudioDekker\Keystone\AppTests\SessionInfoFake;
use PHPUnit\Framework\AssertionFailedError;

it('passes assertParsed once the user agent was parsed', function () {
    $fake = new SessionInfoFake;

    $fake->device('Mozilla/5.0 (X11; Linux x86_64) Firefox/133.0');

    $fake->assertParsed('Mozilla/5.0 (X11; Linux x86_64) Firefox/133.0');
});

it('fails assertParsed when another user agent was parsed', function () {
    $fake = new SessionInfoFake;

    $fake->device('curl/8.7.1');

    expect(fn () => $fake->assertParsed('Mozilla/5.0 (X11; Linux x86_64) Firefox/133.0'))->toThrow(AssertionFailedError::class, 'The user agent [Mozilla/5.0 (X11; Linux x86_64) Firefox/133.0] was not parsed.');
});

it('passes assertNothingParsed when no user agent was parsed', function () {
    $fake = new SessionInfoFake;

    $fake->assertNothingParsed();
});

it('fails assertNothingParsed once a user agent was parsed', function () {
    $fake = new SessionInfoFake;

    $fake->device('curl/8.7.1');

    expect(fn () => $fake->assertNothingParsed())->toThrow(AssertionFailedError::class, 'A user agent was parsed.');
});
