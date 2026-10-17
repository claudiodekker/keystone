<?php

use ClaudioDekker\Keystone\AppTests\KeystoneTestResponse;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;

it('fails assertSignInPage on a redirect', function () {
    $response = $this->actingAs($this->createUser())->get(route('login'));

    expect(fn () => $this->assertSignInPage($response))->toThrow(AssertionFailedError::class);
});

it('fails assertSignInPage on a page showing another status', function () {
    $response = $this->get(route('login'));

    expect(fn () => $this->assertSignInPage($response, ['status' => __('keystone::messages.signed-out')]))->toThrow(AssertionFailedError::class);
});

it('fails assertAlreadySignedIn on the sign-in page', function () {
    $response = $this->get(route('login'));

    expect(fn () => $this->assertAlreadySignedIn($response))->toThrow(AssertionFailedError::class);
});

it('fails assertSignedIn on a refusal', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    expect(fn () => $this->assertSignedIn($response, '/'))->toThrow(AssertionFailedError::class);
});

it('fails assertSignedIn on a sign-in that lands somewhere else', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);
    $this->arrivingFrom(url('/reports'));

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    expect(fn () => $this->assertSignedIn($response, '/'))->toThrow(AssertionFailedError::class);
});

it('fails assertSignInRefused on a sign-in', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->validProof(Surface::SIGN_IN, $user),
    ]);

    expect(fn () => $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected')))->toThrow(AssertionFailedError::class);
});

it('fails assertSignInRefused on a refusal with another message', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    expect(fn () => $this->assertSignInRefused($response, 'No account uses that address.'))->toThrow(AssertionFailedError::class);
});

it('fails assertSignInRefused on a refusal that flashes back the secret', function () {
    $session = new Store('session', new ArraySessionHandler(1));
    $session->flash('errors', (new ViewErrorBag)->put('default', new MessageBag(['identifier' => [__('keystone::messages.credentials-rejected')]])));
    $session->flashInput(['identifier' => 'ada@example.com', 'secret' => 'wrong horse']);
    $response = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new RedirectResponse(route('login'))), $session, 300);

    expect(fn () => $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected')))->toThrow(AssertionFailedError::class, 'The response flashed back more than the identifier.');
});

it('fails assertSignInRefused on a refusal that flashes back no identifier', function () {
    $session = new Store('session', new ArraySessionHandler(1));
    $session->flash('errors', (new ViewErrorBag)->put('default', new MessageBag(['identifier' => [__('keystone::messages.credentials-rejected')]])));
    $response = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new RedirectResponse(route('login'))), $session, 300);

    expect(fn () => $this->assertSignInRefused($response, __('keystone::messages.credentials-rejected')))->toThrow(AssertionFailedError::class, 'The response flashed back no identifier.');
});

it('fails assertSignInInvalid on a refusal', function () {
    $user = $this->createUser();
    $this->addVerifiedAddress($user, 'ada@example.com');
    $support = $this->supportFor(Surface::SIGN_IN);
    $support->arrangeCredential(Surface::SIGN_IN, $user);

    $response = $this->post(route('login.submit', $support->type()->name()), [
        'identifier' => 'ada@example.com',
        ...$support->rejectedProof(Surface::SIGN_IN),
    ]);

    expect(fn () => $this->assertSignInInvalid($response, ['identifier' => __('validation.required', ['attribute' => 'identifier'])]))->toThrow(AssertionFailedError::class);
});

it('fails assertSignInInvalid on a validation failure that flashes back the secret', function () {
    $session = new Store('session', new ArraySessionHandler(1));
    $session->flash('errors', (new ViewErrorBag)->put('default', new MessageBag(['identifier' => ['The identifier field is required.']])));
    $session->flashInput(['secret' => 'wrong horse']);
    $response = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new RedirectResponse(route('login'))), $session, 0);

    expect(fn () => $this->assertSignInInvalid($response, ['identifier']))->toThrow(AssertionFailedError::class, 'The response flashed back more than the identifier.');
});

it('fails assertSignInTypeNotFound on the sign-in page', function () {
    $response = $this->get(route('login'));

    expect(fn () => $this->assertSignInTypeNotFound($response))->toThrow(AssertionFailedError::class);
});

it('fails assertSignedOut on a redirect somewhere else', function () {
    $response = KeystoneTestResponse::capture(TestResponse::fromBaseResponse(new RedirectResponse(url('/'))), new Store('session', new ArraySessionHandler(1)), 0);

    expect(fn () => $this->assertSignedOut($response))->toThrow(AssertionFailedError::class);
});
