<?php

use ClaudioDekker\Keystone\AppTests\Support\AppAlert;
use ClaudioDekker\Keystone\Notifications\SecurityAlert;
use ClaudioDekker\Keystone\Tests\Fixtures\Notifications\AlertFromAnId;
use ClaudioDekker\Keystone\Tests\Fixtures\Notifications\AlertNeedingAMailer;
use ClaudioDekker\Keystone\Tests\Fixtures\Notifications\AlertWithOptions;
use Illuminate\Mail\Mailable;

it('boots with the default slots', function () {
    expect($this->bootFailures())->toBe([]);
});

it('boots with a slot set to null, or to a notification class built from the event alone', function (?string $class) {
    config(['keystone.notifications' => [...config('keystone.notifications'), 'sessions.terminated' => $class, 'signed_in' => $class]]);

    expect($this->bootFailures())->toBe([]);
})->with([
    'null' => null,
    'one that takes the event' => AppAlert::class,
    'one that takes any model and options with defaults' => AlertWithOptions::class,
]);

it('refuses notifications when it is not a map of types', function (mixed $value) {
    config(['keystone.notifications' => $value]);

    expect($this->bootFailures())->toBe(['keystone.notifications must map security event types to a notification class or null.']);
})->with([
    'a class name' => SecurityAlert::class,
    'a list' => [[SecurityAlert::class]],
]);

it('refuses a slot for a type that is no security event type', function () {
    config(['keystone.notifications' => [...config('keystone.notifications'), 'signed_in.twice' => AppAlert::class]]);

    expect($this->bootFailures())->toBe(['keystone.notifications names [signed_in.twice], which is no security event type.']);
});

it('refuses a slot that holds neither null nor a notification class', function (mixed $value) {
    config(['keystone.notifications' => [...config('keystone.notifications'), 'signed_in' => $value]]);

    expect($this->bootFailures())->toBe(['keystone.notifications.signed_in must be null or a notification class.']);
})->with([
    'false' => false,
    'a class that is no notification' => Mailable::class,
    'a class that does not exist' => 'App\Notifications\MissingAlert',
]);

it('refuses a notification class that the event alone cannot build', function (string $class) {
    config(['keystone.notifications' => [...config('keystone.notifications'), 'signed_in' => $class]]);

    expect($this->bootFailures())->toBe(["keystone.notifications.signed_in names [{$class}], whose constructor must take the security event alone."]);
})->with([
    'one that needs another service' => AlertNeedingAMailer::class,
    'one that takes no event' => AlertFromAnId::class,
]);

it('refuses SecurityAlert for a type it has no mail for', function () {
    config(['keystone.notifications' => [...config('keystone.notifications'), 'signed_in' => SecurityAlert::class]]);

    expect($this->bootFailures())->toBe(["keystone.notifications.signed_in names SecurityAlert, which has no mail for that type. Name a notification class of the app's own."]);
});
