<?php

use ClaudioDekker\Keystone\Http\RequestContext;
use Illuminate\Http\Request;

it("captures the address's subnet and the path", function () {
    $request = Request::create('/login/form?next=1', 'POST', server: ['REMOTE_ADDR' => '203.0.113.97']);

    $context = RequestContext::capture($request);

    expect($context->ipAddress)->toBe('203.0.113.97')
        ->and($context->subnet)->toBe('203.0.113.0/24')
        ->and($context->path)->toBe('login/form');
});

it('captures no address, subnet or user agent when the request carries none', function () {
    $request = Request::create('/');
    $request->server->remove('REMOTE_ADDR');
    $request->headers->remove('User-Agent');

    $context = RequestContext::capture($request);

    expect($context->ipAddress)->toBeNull()
        ->and($context->subnet)->toBeNull()
        ->and($context->userAgent)->toBeNull();
});

it('has no context for a request none was captured on', function () {
    expect(RequestContext::of(Request::create('/')))->toBeNull();
});

it('hands back the context stored on the request', function () {
    $request = Request::create('/');
    $context = RequestContext::capture($request);

    $context->storeOn($request);

    expect(RequestContext::of($request))->toBe($context);
});
