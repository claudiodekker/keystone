<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use Illuminate\Support\Facades\Notification;

pest()->extend(AppTestCase::class);

beforeEach(function () {
    Notification::fake();
    $this->withoutMandates();
});

describe('context words', function () {
    it('refuses a password containing a word of the local part of the address being registered, creating nothing', function () {
        $this->registerAddress('jane.doe@example.com');

        $response = $this->post(route('register.finish.submit', ['type' => 'password']), [
            'name' => 'Someone',
            'password' => 'Jane rules 2026!',
            'password_confirmation' => 'Jane rules 2026!',
        ]);

        $response->assertRedirectToRoute('register.finish')
            ->assertSessionHasErrors(['password' => __('keystone-password::messages.context_word', ['attribute' => 'password'])]);
        $this->assertDatabaseCount('users', 0);
    });

    it('lets a password borrow nothing from the address being registered', function () {
        $this->registerAddress('jane.doe@example.com');

        $response = $this->post(route('register.finish.submit', ['type' => 'password']), [
            'name' => 'Someone',
            'password' => 'tq8vbnzw rules 2026!',
            'password_confirmation' => 'tq8vbnzw rules 2026!',
        ]);

        $response->assertRedirect('/');
        $this->assertDatabaseCount('users', 1);
    });
});
