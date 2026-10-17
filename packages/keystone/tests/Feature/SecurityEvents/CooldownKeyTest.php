<?php

// The cooldown key sits in a cache an app may share, so it must not give an address away.

use ClaudioDekker\Keystone\Http\RequestContext;
use ClaudioDekker\Keystone\SecurityEvents\Recorder;
use ClaudioDekker\Keystone\SecurityEvents\SecurityEventType;
use ClaudioDekker\Keystone\Tests\Fixtures\Cache\KeyedStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

it('keeps no address, and no plain hash of it, in the cache key that counts identical anonymous events', function () {
    Cache::extend('keyed', fn () => Cache::repository(new KeyedStore));
    config(['cache.stores.keyed' => ['driver' => 'keyed'], 'cache.default' => 'keyed']);
    $request = Request::create('/login/form', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
    RequestContext::capture($request)->storeOn($request);
    $this->app->instance('request', $request);

    $this->app->make(Recorder::class)->record(SecurityEventType::PROOF_REJECTED, account: null);

    $keys = Cache::store('keyed')->getStore()->keys();

    expect($keys)->toHaveCount(1)
        ->and($keys[0])->not->toContain('203.0.113')
        ->and($keys[0])->not->toContain(hash('sha256', 'proof.rejected|203.0.113.9|login/form'))
        ->and($keys[0])->not->toContain(hash_hmac('sha256', 'proof.rejected|203.0.113.9|login/form', config('app.key')));
});
