<?php

namespace ClaudioDekker\Keystone\AppTests\Concerns;

use ClaudioDekker\Keystone\AppTests\Assertions\GuestStepAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignOutAssertions;
use ClaudioDekker\Keystone\AppTests\KeystoneTestResponse;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserEmail;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Drive the app the way a user does, and check its answers through assertions the app can override.
 *
 * Each request starts afresh: the guards are built again, and the session holds only what its
 * storage keeps for the cookies that earlier responses set, plus what the test put in with
 * withSession(). The next request sees a sign-in only when the session really stored it.
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
     * Give the user a verified address, a made-up one unless given, and return it as stored.
     */
    public function addVerifiedAddress(KeystoneUser $user, ?string $address = null): string
    {
        $email = UserEmail::factory()
            ->for($user, 'user')
            ->verified()
            ->create(array_filter(['address' => $address]));

        return $email->address;
    }

    /**
     * Give the user an address nobody verified, and return it as stored.
     */
    public function addUnverifiedAddress(KeystoneUser $user, string $address): string
    {
        $email = UserEmail::factory()
            ->for($user, 'user')
            ->create(['address' => $address]);

        return $email->address;
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
     * Send the next requests without the cookies earlier responses set, as someone else would.
     *
     * @return $this
     */
    public function withoutCookies(): static
    {
        $this->defaultCookies = [];

        return $this;
    }

    /**
     * Get the cookies the next request sends, to send them again later with withCookies().
     *
     * @return array<string, string>
     */
    public function cookies(): array
    {
        return $this->defaultCookies;
    }

    /**
     * Assert the two responses match in status, redirect, flashed data, header and cookie names, and the time they took.
     */
    public function assertIndistinguishable(KeystoneTestResponse $existing, KeystoneTestResponse $missing): void
    {
        Assert::assertSame($existing->getStatusCode(), $missing->getStatusCode(), 'The responses differ in status.');
        Assert::assertSame($existing->headers->get('Location'), $missing->headers->get('Location'), 'The responses differ in redirect.');
        Assert::assertEquals($this->comparableFlash($existing), $this->comparableFlash($missing), 'The responses differ in flashed data.');
        Assert::assertSame($this->headerNames($existing), $this->headerNames($missing), 'The responses differ in header names.');
        Assert::assertSame($this->cookieNames($existing), $this->cookieNames($missing), 'The responses differ in Set-Cookie names.');
        Assert::assertSame($existing->milliseconds(), $missing->milliseconds(), 'The responses differ in the time they took on the test clock.');
    }

    /**
     * Assert the request took at least the given time on the test clock.
     */
    public function assertTookAtLeast(KeystoneTestResponse $response, int $milliseconds): void
    {
        Assert::assertGreaterThanOrEqual($milliseconds, $response->milliseconds(), "The request took {$response->milliseconds()} ms on the test clock.");
    }

    /**
     * Send the request with fresh guards and the cookies earlier responses set, then forget the session data it left in memory.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): KeystoneTestResponse
    {
        $this->app['auth']->forgetGuards();

        if ($this->actor !== null) {
            $this->app['auth']->guard()->setUser($this->actor);
        }

        $startedAt = Date::now();

        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        $this->keepCookies($response);

        $session = clone $this->app['session.store'];

        $this->app['session.store']->flush();

        return KeystoneTestResponse::capture($response, $session, $startedAt->diffInMilliseconds(Date::now()));
    }

    /**
     * Keep the cookies the response set for the next request.
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
