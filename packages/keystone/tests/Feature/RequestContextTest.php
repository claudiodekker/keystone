<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RequestContext;
use ClaudioDekker\Keystone\SecurityEvent;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use Illuminate\Support\Str;

pest()->extend(AppTestCase::class);

it('captures each request\'s IP address, user agent and a request id of its own', function () {
    $account = $this->createAccount();
    $this->arrangeCredential($account, new FormTypeSupport, Surface::SIGN_IN);

    $this->withHeader('User-Agent', 'KeystoneTest/1.0')
        ->post(route('login.submit', ['type' => 'form']), ['identifier' => 'jane@example.com', ...(new FormTypeSupport)->validProof(Surface::SIGN_IN)]);
    $this->post(route('logout'));

    [$signedIn, $signedOut] = SecurityEvent::query()->orderBy('id')->get()->all();
    expect($signedIn->ip_address)->toBe('127.0.0.1')
        ->and($signedIn->user_agent)->toBe('KeystoneTest/1.0')
        ->and(Str::isUlid($signedIn->request_id))->toBeTrue()
        ->and($signedOut->request_id)->not->toBe($signedIn->request_id);
});

it('forgets the captured context when the app resets between requests', function () {
    $this->get(route('login'));
    expect($this->app->make(RequestContext::class)->requestId)->not->toBeNull();

    $this->app->forgetScopedInstances();

    expect($this->app->make(RequestContext::class))->toEqual(new RequestContext);
});
