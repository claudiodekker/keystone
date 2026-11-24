<?php

use ClaudioDekker\Keystone\Http\Subnet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('sends the requests from an address on another network than the test sends from by default', function () {
    Route::get('/keystone/app-tests/address', fn (Request $request) => $request->ip());

    $default = $this->get('/keystone/app-tests/address')->getContent();
    $moved = $this->fromAnotherNetwork()->get('/keystone/app-tests/address')->getContent();

    expect(Subnet::of($moved))->not->toBeNull()
        ->and(Subnet::of($moved))->not->toBe(Subnet::of($default));
});

it('sends the requests from the address the test sends from by default again once back on its own network', function () {
    Route::get('/keystone/app-tests/address', fn (Request $request) => $request->ip());

    $default = $this->get('/keystone/app-tests/address')->getContent();
    $this->fromAnotherNetwork()->get('/keystone/app-tests/address');
    $back = $this->fromOwnNetwork()->get('/keystone/app-tests/address')->getContent();

    expect($back)->toBe($default);
});
