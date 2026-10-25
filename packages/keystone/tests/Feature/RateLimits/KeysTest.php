<?php

// The limiter's keys sit in a cache an app may share, so they must give neither an identifier nor an address away.

use ClaudioDekker\Keystone\Http\RequestContext;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RateLimits\Limiter;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use ClaudioDekker\Keystone\Tests\Fixtures\Cache\KeyedStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

it('keeps no identifier or address, and no plain hash of either, in the limiter store', function () {
    $store = new KeyedStore;
    $this->app->instance(RateLimiter::class, new RateLimiter(Cache::repository($store)));
    $request = Request::create('/login/form', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
    RequestContext::capture($request)->storeOn($request);
    $this->app->instance('request', $request);
    $type = app(CredentialTypes::class)->find('form', Surface::SIGN_IN);
    $limiter = $this->app->make(Limiter::class);

    $limiter->takeRequest(StepKind::SUBMIT);
    $limiter->takeFailedAttempt($type, Surface::SIGN_IN, null, 'grace@example.com');

    $keys = $store->keys();

    expect($keys)->toHaveCount(4)->each->toStartWith('keystone:rate_limit:');
    expect(implode(' ', $keys))
        ->not->toContain('grace')
        ->not->toContain('203.0.113')
        ->not->toContain(hash('sha256', 'grace@example.com'))
        ->not->toContain(hash('sha256', '203.0.113.9'))
        ->not->toContain(hash_hmac('sha256', 'grace@example.com', config('app.key')))
        ->not->toContain(hash_hmac('sha256', '203.0.113.9', config('app.key')));
});
