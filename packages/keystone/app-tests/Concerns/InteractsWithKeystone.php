<?php

namespace ClaudioDekker\Keystone\AppTests\Concerns;

use ClaudioDekker\Keystone\AppTests\Assertions\GuestStepAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignOutAssertions;
use ClaudioDekker\Keystone\AppTests\KeystoneTestResponse;
use ClaudioDekker\Keystone\AppTests\Support\Browser;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Drive the app the way a user does, and check its answers through assertions the app can override.
 *
 * Every request starts afresh, like a new HTTP request from one browser: the guards are built again, the
 * session holds only what its storage keeps for the browser's cookie, and only the cookies earlier
 * responses set carry over. So the next request sees a sign-in only when the session really stored
 * it.
 *
 * @api
 */
trait InteractsWithKeystone
{
    use GuestStepAssertions;
    use LazilyRefreshDatabase;
    use SignInAssertions;
    use SignOutAssertions;

    /**
     * The user actingAs() puts on every request of the test, without a session.
     */
    protected ?Authenticatable $actor = null;

    /**
     * Create a user from the app's own factory, naming the fix when the app has none.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createUser(array $attributes = []): KeystoneUser
    {
        $model = Keystone::userModel();

        if (! method_exists($model, 'factory')) {
            Assert::fail("The user model [{$model}] has no factory. Give it HasFactory and a factory, as Keystone takes its users from the app.");
        }

        return $model::factory()->create($attributes);
    }

    /**
     * Get the test support of the first installed type that serves the surface, naming the fix when none does.
     */
    public function supportFor(Surface $surface): CredentialTypeSupport
    {
        return $this->supportsFor($surface)[0] ?? Assert::fail("No installed credential type with test support serves [{$surface->value}]. Install a method package that does.");
    }

    /**
     * Get the test support of every installed type that serves the surface.
     *
     * @return list<CredentialTypeSupport>
     */
    public function supportsFor(Surface $surface): array
    {
        return collect(Keystone::credentialTypes()->serving($surface))
            ->whereInstanceOf(CredentialTypeSupport::class)
            ->values()
            ->all();
    }

    /**
     * Put the user on every request of the test without a session, as Laravel's actingAs() does.
     *
     * @param  string|null  $guard
     * @return $this
     */
    public function be(Authenticatable $user, $guard = null)
    {
        $this->actor = $user;

        return parent::be($user, $guard);
    }

    /**
     * Continue in a browser that holds no cookies, as someone else would.
     *
     * @return $this
     */
    public function freshBrowser(): static
    {
        $this->defaultCookies = [];

        return $this;
    }

    /**
     * Get the cookies the browser holds now, to come back with later.
     */
    public function browser(): Browser
    {
        return new Browser($this->defaultCookies);
    }

    /**
     * Continue in a browser that holds the given cookies, as someone coming back with old cookies would.
     *
     * @return $this
     */
    public function returnWith(Browser $browser): static
    {
        $this->defaultCookies = $browser->cookies;

        return $this;
    }

    /**
     * Keep the URL as intended in the browser's session, where Laravel's guest redirect keeps it before sending a guest to sign in.
     *
     * @return $this
     */
    public function arrivingFrom(string $url): static
    {
        $this->duringRequest(fn () => $this->app['session.store']->put('url.intended', $url));

        return $this;
    }

    /**
     * Run app code inside a request from the browser, so whatever it leaves in the session reaches the next request.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $code
     * @return TResult
     */
    public function duringRequest(Closure $code): mixed
    {
        $this->startRequest();

        $session = $this->app['session.store'];

        $session->setId($this->defaultCookies[$session->getName()] ?? null);
        $session->start();

        $result = $code();

        $session->save();

        $this->defaultCookies[$session->getName()] = $session->getId();

        return $result;
    }

    /**
     * Assert the two responses match in status, redirect, flashed data, and header and cookie names.
     */
    public function assertIndistinguishable(KeystoneTestResponse $existing, KeystoneTestResponse $missing): void
    {
        Assert::assertSame($existing->getStatusCode(), $missing->getStatusCode(), 'The responses differ in status.');
        Assert::assertSame($existing->headers->get('Location'), $missing->headers->get('Location'), 'The responses differ in redirect.');
        Assert::assertEquals($this->comparableFlash($existing), $this->comparableFlash($missing), 'The responses differ in flashed data.');
        Assert::assertSame($this->headerNames($existing), $this->headerNames($missing), 'The responses differ in header names.');
        Assert::assertSame($this->cookieNames($existing), $this->cookieNames($missing), 'The responses differ in Set-Cookie names.');
    }

    /**
     * Assert the request took at least the given time on the test clock.
     */
    public function assertTookAtLeast(KeystoneTestResponse $response, int $milliseconds): void
    {
        Assert::assertGreaterThanOrEqual($milliseconds, $response->milliseconds(), "The request took {$response->milliseconds()} ms on the test clock.");
    }

    /**
     * Send the request from the browser, starting the app's request state afresh.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): KeystoneTestResponse
    {
        $this->startRequest();

        $startedAt = Date::now();

        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        $this->keepCookies($response);

        $session = clone $this->app['session.store'];

        return KeystoneTestResponse::capture($response, $session, $startedAt->diffInMilliseconds(Date::now()));
    }

    /**
     * Forget the guards and the session data an earlier request left in memory.
     */
    protected function startRequest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();

        if ($this->actor !== null) {
            $this->app['auth']->guard()->setUser($this->actor);
        }
    }

    /**
     * Keep the cookies the response set for the browser's next request.
     */
    protected function keepCookies(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->defaultCookies[$cookie->getName()] = $response->getCookie($cookie->getName())->getValue();
        }
    }

    /**
     * Get the response's flashed data with the flashed-back input reduced to its fields.
     *
     * @return array<string, mixed>
     */
    protected function comparableFlash(KeystoneTestResponse $response): array
    {
        return [
            ...$response->flashed(),
            '_old_input' => array_keys($response->oldInput()),
        ];
    }

    /**
     * Get the response's header names, lowercase and sorted.
     *
     * @return list<string>
     */
    protected function headerNames(KeystoneTestResponse $response): array
    {
        return collect($response->headers->all())
            ->keys()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Get the names of the cookies the response sets, sorted.
     *
     * @return list<string>
     */
    protected function cookieNames(KeystoneTestResponse $response): array
    {
        return collect($response->headers->getCookies())
            ->map(fn (Cookie $cookie) => $cookie->getName())
            ->sort()
            ->values()
            ->all();
    }
}
