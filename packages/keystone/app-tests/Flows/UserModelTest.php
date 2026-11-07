<?php

it("keeps Keystone's users columns out of the user model's array form", function () {
    $user = $this->createUser();

    $array = $user->fresh()->toArray();

    expect($array)->not->toHaveKey('credential_epoch');
});
