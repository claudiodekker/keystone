<?php

use ClaudioDekker\Keystone\Status;
use Illuminate\Support\Facades\Lang;

it('has a keystone:: message for every status', function (Status $status) {
    expect(Lang::has('keystone::messages.'.$status->value, 'en', false))->toBeTrue();
})->with(Status::cases());

it('tells the user each status in the words the spec gives', function (Status $status, string $message) {
    expect($status->label())->toBe($message);
})->with([
    'credentials rejected' => [Status::CREDENTIALS_REJECTED, 'These credentials do not match our records.'],
    'signed out' => [Status::SIGNED_OUT, 'You have been logged out.'],
]);
