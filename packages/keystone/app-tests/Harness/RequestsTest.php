<?php

use PHPUnit\Framework\AssertionFailedError;

it('puts the headers appSetsHeaders names on the responses of the app', function () {
    $this->appSetsHeaders(['X-App' => 'yes']);

    $response = $this->get(route('login'));

    expect($response->headers->get('X-App'))->toBe('yes');
});

it('lets appSetsHeaders name several values of one header', function () {
    $this->appSetsHeaders(['X-App' => ['one', 'two']]);

    $response = $this->get(route('login'));

    expect($response->headers->all('x-app'))->toBe(['one', 'two']);
});

it('sends Sec-Fetch-Site same-origin by default, as a browser does from the app\'s own pages', function () {
    $response = $this->post(route('logout'));

    $this->assertSignedOut($response);
});

it('sends the requests after fromAnotherSite as a page on another site would', function () {
    $response = $this->fromAnotherSite()->post(route('logout'));

    $this->assertCrossSiteRefused($response);
});

it('fails assertCrossSiteRefused on a request the cross-site check let through', function () {
    $response = $this->post(route('logout'));

    expect(fn () => $this->assertCrossSiteRefused($response))->toThrow(AssertionFailedError::class);
});
