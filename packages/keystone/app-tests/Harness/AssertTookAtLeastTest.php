<?php

use ClaudioDekker\Keystone\AppTests\KeystoneTestResponse;
use Illuminate\Http\Response;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;

it('passes a request that took the whole time on the test clock', function () {
    $response = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response), new Store('session', new ArraySessionHandler(1)), 300);

    $this->assertTookAtLeast($response, 300);
});

it('fails a request that took less time on the test clock', function () {
    $response = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new Response), new Store('session', new ArraySessionHandler(1)), 299);

    expect(fn () => $this->assertTookAtLeast($response, 300))->toThrow(AssertionFailedError::class, 'The request took 299 ms on the test clock.');
});
