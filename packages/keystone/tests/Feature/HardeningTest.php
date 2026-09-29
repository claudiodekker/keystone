<?php

use ClaudioDekker\Keystone\AppTests\AppTestCase;
use ClaudioDekker\Keystone\Tests\Fixtures\FormTypeSupport;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\OverridingSignInController;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

pest()->extend(AppTestCase::class);

describe('the hardening floor', function () {
    beforeEach(function () {
        Route::middleware('web')->get('overridden/login', [OverridingSignInController::class, 'show']);
        Route::middleware('web')->match(['get', 'post'], 'elsewhere', fn () => 'The app\'s own page.');
    });

    it('sends only the floor\'s policy directives when the app sends no policy, never script-src', function () {
        $response = $this->get(route('login'));

        $response->assertHeader('Content-Security-Policy', "object-src 'none'; base-uri 'none'; frame-ancestors 'none'");
    });

    it('keeps each of the app\'s policies with its own directives, forcing the floor\'s over them', function () {
        $response = $this->get('overridden/login');

        expect($response->headers->all('Content-Security-Policy'))->toBe([
            "default-src 'self'; script-src 'self' 'nonce-app'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'",
            "img-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    });

    it('replaces the app\'s conflicting headers with the floor\'s', function () {
        $response = $this->get('overridden/login');

        $response->assertHeader('X-Frame-Options', 'DENY');
        expect($response->headers->getCacheControlDirective('public'))->toBeNull()
            ->and($response->headers->getCacheControlDirective('no-store'))->toBeTrue()
            ->and($response->headers->getCacheControlDirective('max-age'))->toBe('0');
    });

    it('keeps throttling a controller whose action the app replaced', function () {
        foreach (range(1, 60) as $ignored) {
            $this->get('overridden/login');
        }

        $response = $this->get('overridden/login');

        $response->assertTooManyRequests()->assertHeader('Retry-After');
    });

    it('leaves the app\'s own responses alone', function () {
        $response = $this->get('elsewhere');

        $response->assertHeaderMissing('X-Frame-Options')->assertHeaderMissing('Content-Security-Policy');
    });
});

describe('cross-site requests', function () {
    it('refuses a Keystone mutation that can\'t show where it came from', function (array $headers) {
        $this->signInAccount(new FormTypeSupport);

        $response = $this->withoutHeader('Sec-Fetch-Site')->withHeaders($headers)->post(route('logout'));

        $response->assertStatus(419);
        $this->assertAuthenticated();
    })->with([
        'a cross-site fetch' => [['Sec-Fetch-Site' => 'cross-site']],
        'a same-site fetch' => [['Sec-Fetch-Site' => 'same-site']],
        'no fetch metadata' => [[]],
        'a foreign origin' => [['Origin' => 'https://attacker.example']],
        'a wrong token' => [['X-CSRF-TOKEN' => 'wrong-token']],
        'an undecryptable XSRF token' => [['X-XSRF-TOKEN' => 'not-encrypted']],
    ]);

    it('lets a Keystone mutation through that shows it came from the app', function (Closure $headers) {
        $this->signInAccount(new FormTypeSupport);
        $sent = $headers->call($this, session()->token());

        $response = $this->withoutHeader('Sec-Fetch-Site')->withHeaders($sent)->post(route('logout'));

        $response->assertRedirectToRoute('login');
        $this->assertGuest();
    })->with([
        'a same-origin fetch' => fn () => ['Sec-Fetch-Site' => 'same-origin'],
        'the session token in a header' => fn (string $token) => ['X-CSRF-TOKEN' => $token],
        'the encrypted XSRF token' => fn (string $token) => ['X-XSRF-TOKEN' => Crypt::encrypt(CookieValuePrefix::create('XSRF-TOKEN', Crypt::getKey()).$token, PreventRequestForgery::serialized())],
        'the app\'s own origin' => fn () => ['Origin' => 'http://localhost'],
    ]);

    it('lets a Keystone mutation through with the session token in its form', function () {
        $this->signInAccount(new FormTypeSupport);

        $response = $this->withoutHeader('Sec-Fetch-Site')->post(route('logout'), ['_token' => session()->token()]);

        $response->assertRedirectToRoute('login');
        $this->assertGuest();
    });

    it('records the refusal on the signed-in account\'s trail', function () {
        $account = $this->signInAccount(new FormTypeSupport);

        $this->withHeader('Sec-Fetch-Site', 'cross-site')->post(route('logout'));

        $this->assertDatabaseHas('user_security_events', ['type' => 'request.rejected', 'user_id' => $account->getKey(), 'reason' => 'keystone.cross_site']);
    });

    it('lets reads and the app\'s own mutations through', function () {
        Route::middleware('web')->post('elsewhere', fn () => 'The app\'s own mutation.');

        $read = $this->withHeader('Sec-Fetch-Site', 'cross-site')->get(route('login'));
        $mutation = $this->withHeader('Sec-Fetch-Site', 'cross-site')->post('elsewhere');

        $read->assertOk();
        $mutation->assertOk();
    });
});

describe('clearing site data', function () {
    it('clears site data when the account\'s credential epoch moved', function () {
        $this->signInAccount(new FormTypeSupport);
        DB::table('users')->increment('credential_epoch');

        $response = $this->get(route('login'));

        $response->assertHeader('Clear-Site-Data', '"cache", "storage"');
        $this->assertGuest();
    });

    it('clears site data when the account was deleted', function () {
        $this->signInAccount(new FormTypeSupport);
        DB::table('users')->update(['deleted_at' => now()]);

        $response = $this->get(route('login'));

        $response->assertHeader('Clear-Site-Data', '"cache", "storage"');
    });

    it('clears site data from any response of the request that ended the session', function () {
        Route::middleware('web')->get('elsewhere', fn () => auth()->check() ? 'Signed in.' : 'Signed out.');
        $this->signInAccount(new FormTypeSupport);
        DB::table('users')->increment('credential_epoch');

        $response = $this->get('elsewhere');

        $response->assertHeader('Clear-Site-Data', '"cache", "storage"');
    });

    it('leaves site data alone while the session goes on', function () {
        $this->signInAccount(new FormTypeSupport);

        $response = $this->get(route('login'));

        $response->assertHeaderMissing('Clear-Site-Data');
    });
});
