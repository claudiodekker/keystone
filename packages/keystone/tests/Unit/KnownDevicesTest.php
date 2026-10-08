<?php

use ClaudioDekker\Keystone\KnownDevices;
use ClaudioDekker\Keystone\Tests\Fixtures\User;

it('never takes an account without a key', function () {
    expect(fn () => new KnownDevices(new User))->toThrow(LogicException::class);
});
