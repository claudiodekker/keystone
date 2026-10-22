<?php

use ClaudioDekker\Keystone\Http\ContentSecurityPolicy;

it('reads each comma-separated policy of a header on its own', function () {
    $policies = ContentSecurityPolicy::parseList("default-src 'self', img-src https://img.example.com; style-src 'none'");

    expect(array_map(fn (ContentSecurityPolicy $policy) => $policy->toString(), $policies))->toBe([
        "default-src 'self'",
        "img-src https://img.example.com; style-src 'none'",
    ]);
});

it('keeps the first of a repeated directive, as a browser does', function () {
    $policy = ContentSecurityPolicy::parse("script-src 'self'; img-src data:; script-src *");

    expect($policy->toString())->toBe("script-src 'self'; img-src data:");
});

it('reads directive names in any case', function () {
    $policy = ContentSecurityPolicy::parse("Frame-Ancestors *; FRAME-ANCESTORS 'self'");

    expect($policy->toString())->toBe('frame-ancestors *');
});

it('drops empty directives and the whitespace around the rest', function () {
    $policy = ContentSecurityPolicy::parse("  ;; default-src   'self'  'unsafe-inline' ; ;sandbox;");

    expect($policy->toString())->toBe("default-src 'self' 'unsafe-inline'; sandbox");
});

it('writes a directive in place of the value it had, and a new one last', function () {
    $policy = ContentSecurityPolicy::parse('object-src *; img-src data:')
        ->with('object-src', "'none'")
        ->with('base-uri', "'none'");

    expect($policy->toString())->toBe("object-src 'none'; img-src data:; base-uri 'none'");
});
