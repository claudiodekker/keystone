<?php

use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;

it("keeps Keystone's users columns out of the user model's array form", function () {
    $user = $this->createUser();

    $array = $user->fresh()->toArray();

    expect($array)->not->toHaveKey('credential_epoch')
        ->and($array)->not->toHaveKey('suspended_at');
});

it('mass-assigns no credential epoch, even while mass assignment is unguarded, so no ended session comes back', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    Artisan::call('keystone:end-sessions', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);
    rescue(fn () => Model::unguarded(fn () => $user->fresh()->update(['credential_epoch' => 0])), report: false);

    Auth::forgetGuards();
    $response = $this->get(route('login'));

    $this->assertSignInPage($response);
});

it('mass-assigns no suspension, even while mass assignment is unguarded, so a suspended account stays refused', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

    Artisan::call('keystone:suspend', ['user' => $user->getAuthIdentifier(), '--operator' => 'margaret']);
    rescue(fn () => Model::unguarded(fn () => $user->fresh()->update(['suspended_at' => null])), report: false);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected'));
});
