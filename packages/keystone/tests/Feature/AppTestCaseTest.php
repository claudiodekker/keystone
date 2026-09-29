<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Http\Middleware\AddHardeningHeaders;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Proof;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Tests\Fixtures\LayeredProbeAssertions;
use ClaudioDekker\Keystone\Tests\Fixtures\ProbeAssertions;
use ClaudioDekker\Keystone\Tests\Fixtures\RogueType;
use ClaudioDekker\Keystone\Tests\Fixtures\UnrelatedProbeAssertions;
use ClaudioDekker\Keystone\Tests\Fixtures\UserWithoutFactory;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

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

it('fails clearly when a type has no test support', function () {
    $this->app->make(CredentialTypes::class)->register(new RogueType(fn () => Proof::rejected('rogue.mismatch')));

    $this->supportsFor(Surface::SIGN_IN);
})->throws(AssertionFailedError::class, 'No test support for the [rogue] credential type: add ClaudioDekker\\Keystone\\Tests\\Fixtures\\AppTests\\Support\\RogueTypeSupport, or bind one under [keystone.test-support.rogue].');

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

    it('uses the app\'s copy that reaches Keystone\'s trait through its adapter\'s', function () {
        eval('namespace ClaudioDekker\Keystone\Tests\Fixtures; trait AdapterProbeAssertions { use LayeredProbeAssertions; }');
        eval('namespace Tests\Keystone\Assertions; trait LayeredProbeAssertions { use \ClaudioDekker\Keystone\Tests\Fixtures\AdapterProbeAssertions; }');

        expect(AppTestCase::assertions(LayeredProbeAssertions::class))->toBe('Tests\Keystone\Assertions\LayeredProbeAssertions');
    });

    it('refuses an app copy that does not use Keystone\'s trait', function () {
        eval('namespace Tests\Keystone\Assertions; trait UnrelatedProbeAssertions {}');

        AppTestCase::assertions(UnrelatedProbeAssertions::class);
    })->throws(LogicException::class, 'Tests\Keystone\Assertions\UnrelatedProbeAssertions must use '.UnrelatedProbeAssertions::class);
});

describe('assertHardeningFloor', function () {
    function routeHardeningProbe(Closure $weaken): void
    {
        Route::get('probe', function () use ($weaken) {
            $response = response('')->withHeaders([
                ...AddHardeningHeaders::HEADERS,
                'Content-Security-Policy' => "img-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'",
            ]);

            $weaken($response->headers);

            return $response;
        });
    }

    it('passes a response carrying the floor', function () {
        routeHardeningProbe(fn () => null);

        $this->assertHardeningFloor($this->get('probe'));
    });

    it('fails a response missing any part of the floor', function (Closure $weaken) {
        routeHardeningProbe($weaken);
        $response = $this->get('probe');

        expect(fn () => $this->assertHardeningFloor($response))->toThrow(AssertionFailedError::class);
    })->with([
        'a storable response' => fn (ResponseHeaderBag $headers) => $headers->set('Cache-Control', 'max-age=0, must-revalidate'),
        'a cacheable response' => fn (ResponseHeaderBag $headers) => $headers->set('Cache-Control', 'no-store, max-age=60, must-revalidate'),
        'a response served stale' => fn (ResponseHeaderBag $headers) => $headers->set('Cache-Control', 'no-store, max-age=0'),
        'no Pragma' => fn (ResponseHeaderBag $headers) => $headers->remove('Pragma'),
        'a sniffable type' => fn (ResponseHeaderBag $headers) => $headers->remove('X-Content-Type-Options'),
        'a looser referrer policy' => fn (ResponseHeaderBag $headers) => $headers->set('Referrer-Policy', 'unsafe-url'),
        'a shared opener' => fn (ResponseHeaderBag $headers) => $headers->set('Cross-Origin-Opener-Policy', 'unsafe-none'),
        'a cross-origin resource' => fn (ResponseHeaderBag $headers) => $headers->set('Cross-Origin-Resource-Policy', 'cross-origin'),
        'a frameable response' => fn (ResponseHeaderBag $headers) => $headers->set('X-Frame-Options', 'SAMEORIGIN'),
        'no policy' => fn (ResponseHeaderBag $headers) => $headers->remove('Content-Security-Policy'),
        'no forced object-src' => fn (ResponseHeaderBag $headers) => $headers->set('Content-Security-Policy', "base-uri 'none'; frame-ancestors 'none'"),
        'no forced base-uri' => fn (ResponseHeaderBag $headers) => $headers->set('Content-Security-Policy', "object-src 'none'; frame-ancestors 'none'"),
        'no forced frame-ancestors' => fn (ResponseHeaderBag $headers) => $headers->set('Content-Security-Policy', "object-src 'none'; base-uri 'none'"),
        'a looser frame-ancestors first' => fn (ResponseHeaderBag $headers) => $headers->set('Content-Security-Policy', "frame-ancestors *; object-src 'none'; base-uri 'none'; frame-ancestors 'none'"),
        'a second policy without the floor' => fn (ResponseHeaderBag $headers) => $headers->set('Content-Security-Policy', ["object-src 'none'; base-uri 'none'; frame-ancestors 'none'", "img-src 'self'"]),
    ]);
});
