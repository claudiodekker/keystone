<?php

it('reads the remember-me lifetime from the app', function () {
    config(['keystone.remember.lifetime_seconds' => 7 * 24 * 60 * 60]);

    expect($this->rememberLifetimeSeconds())->toBe(604800);
});
