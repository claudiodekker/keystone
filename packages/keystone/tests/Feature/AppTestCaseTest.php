<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Tests\Fixtures\ProbeAssertions;
use ClaudioDekker\Keystone\Tests\Fixtures\UnrelatedProbeAssertions;
use ClaudioDekker\Keystone\Tests\Fixtures\UserWithoutFactory;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
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

it('counts rate limits in a fresh in-memory store', function () {
    $counter = $this->app->make(CacheRateLimiter::class);
    $store = (fn () => $this->cache)->call($counter)->getStore();

    expect($store)->toBeInstanceOf(ArrayStore::class)
        ->and(config('cache.limiter'))->toBe('array');
});

it('runs each type\'s scenario in a fresh session, signed out', function () {
    $seen = [];

    $this->eachSupportFor(Surface::SIGN_IN, function (CredentialTypeSupport $support) use (&$seen) {
        $account = $this->createAccount("{$support->type()}@example.com");
        $this->arrangeCredential($account, $support, Surface::SIGN_IN);
        $seen[] = $this->get(route('login'))->isRedirect();

        $this->post(route('login.submit', ['type' => $support->type()]), ['identifier' => "{$support->type()}@example.com", ...$support->validProof(Surface::SIGN_IN)]);
    });

    expect($seen)->toBe([false, false]);
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

describe('assertions', function () {
    it('uses Keystone\'s assertion trait when the app has no copy', function () {
        expect(AppTestCase::assertions(SignInAssertions::class))->toBe(SignInAssertions::class);
    });

    it('uses the app\'s copy of the assertion trait', function () {
        eval('namespace Tests\Keystone\Assertions; trait ProbeAssertions { use \ClaudioDekker\Keystone\Tests\Fixtures\ProbeAssertions; }');

        expect(AppTestCase::assertions(ProbeAssertions::class))->toBe('Tests\Keystone\Assertions\ProbeAssertions');
    });

    it('refuses an app copy that does not use Keystone\'s trait', function () {
        eval('namespace Tests\Keystone\Assertions; trait UnrelatedProbeAssertions {}');

        AppTestCase::assertions(UnrelatedProbeAssertions::class);
    })->throws(LogicException::class, 'Tests\Keystone\Assertions\UnrelatedProbeAssertions must use '.UnrelatedProbeAssertions::class);
});
