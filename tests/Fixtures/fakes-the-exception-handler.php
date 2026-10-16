<?php

use Illuminate\Support\Facades\Exceptions;

it('fakes the exception handler itself', function () {
    Exceptions::fake();
});
