<?php

it('keeps an actingAs() user on every request of the test', function () {
    $user = $this->createUser();
    $this->actingAs($user)->get(route('login'));

    $response = $this->get(route('login'));

    $this->assertAlreadySignedIn($response);
});
