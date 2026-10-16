<?php

use ClaudioDekker\Keystone\KeystoneServiceProvider;

it('boots in an app that discovers it', function () {
    expect($this->app->providerIsLoaded(KeystoneServiceProvider::class))->toBeTrue();
});
