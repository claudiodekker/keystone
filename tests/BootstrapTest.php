<?php

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

it('fails an http call nothing faked', function () {
    expect(fn () => Http::get('https://keystone.test'))->toThrow(StrayRequestException::class);
});
