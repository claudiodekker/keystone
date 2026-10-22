<?php

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
