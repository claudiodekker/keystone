<?php

it('refuses an empty, array or over-long password', function (mixed $password, string $rule) {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');

    $response = $this->post(route('login.submit', 'password'), ['identifier' => 'ada@example.com', 'password' => $password]);

    $this->assertSignInInvalid($response, [
        'password' => __("validation.{$rule}", ['attribute' => 'password', 'max' => 1024]),
    ]);
})->with([
    'empty' => ['', 'required'],
    'an array' => [['odd horse'], 'string'],
    '1025 characters' => [str_repeat('a', 1025), 'max.string'],
]);
