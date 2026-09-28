<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Responses\SignInResponses;
use ClaudioDekker\Keystone\Tests\Fixtures\ProbeResponses;
use ClaudioDekker\Keystone\Tests\Fixtures\UnrelatedProbeResponses;
use ClaudioDekker\Keystone\Tests\Fixtures\UserWithoutFactory;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;

pest()->extend(AppTestCase::class);

it('fails clearly when the user model has no factory', function () {
    config(['auth.providers.users.model' => UserWithoutFactory::class]);

    $this->userFactory();
})->throws(AssertionFailedError::class, "Keystone's AppTests create users with ".UserWithoutFactory::class.'::factory()');

it('sends Sec-Fetch-Site as a same-origin browser would', function () {
    Route::get('probe', fn () => request()->header('Sec-Fetch-Site'));

    $this->get('probe')->assertContent('same-origin');
});

describe('assertIndistinguishable', function () {
    beforeEach(function () {
        Route::middleware('web')->group(function () {
            Route::get('alike', fn () => redirect('/there')->with('status', 'same'));
            Route::get('other-status', fn () => response('', 404));
            Route::get('other-location', fn () => redirect('/elsewhere')->with('status', 'same'));
            Route::get('other-header', fn () => redirect('/there')->with('status', 'same')->header('X-Account', 'found'));
            Route::get('other-cookie', fn () => redirect('/there')->with('status', 'same')->cookie('account', 'found'));
            Route::get('other-flash', fn () => redirect('/there')->with('status', 'different'));
        });
    });

    it('passes for responses alike', function () {
        $this->assertIndistinguishable(fn () => $this->get('alike'), fn () => $this->get('alike'));
    });

    it('fails on a difference', function (string $uri) {
        expect(fn () => $this->assertIndistinguishable(fn () => $this->get('alike'), fn () => $this->get($uri)))
            ->toThrow(AssertionFailedError::class, 'The responses differ.');
    })->with(['other-status', 'other-location', 'other-header', 'other-cookie', 'other-flash']);
});

describe('responses', function () {
    it('uses the default Responses class when the app has no override', function () {
        expect($this->responses(SignInResponses::class))->toBeInstanceOf(SignInResponses::class);
    });

    it('uses the app\'s override', function () {
        eval('namespace Tests\Keystone\Responses; class ProbeResponses extends \ClaudioDekker\Keystone\Tests\Fixtures\ProbeResponses {}');

        expect($this->responses(ProbeResponses::class))->toBeInstanceOf('Tests\Keystone\Responses\ProbeResponses');
    });

    it('fails when the app\'s override does not extend the default', function () {
        eval('namespace Tests\Keystone\Responses; class UnrelatedProbeResponses {}');

        $this->responses(UnrelatedProbeResponses::class);
    })->throws(AssertionFailedError::class, 'Tests\Keystone\Responses\UnrelatedProbeResponses must extend '.UnrelatedProbeResponses::class);
});
