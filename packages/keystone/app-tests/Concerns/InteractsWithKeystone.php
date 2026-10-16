<?php

namespace ClaudioDekker\Keystone\AppTests\Concerns;

use ClaudioDekker\Keystone\AppTests\Assertions\GuestStepAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\AppTests\KeystoneTestResponse;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Testing\TestResponse;

/**
 * Drive the app the way a user does, and check its answers through assertions the app can override.
 *
 * Every request starts afresh, like a new HTTP request from one browser: the guards and the session store
 * are built again, and only the cookies earlier responses set carry over. So the next request sees a
 * sign-in only when the session really stored it. The harness grows only through the named helpers
 * here, each with its own test under app-tests/Harness.
 *
 * @api
 */
trait InteractsWithKeystone
{
    use GuestStepAssertions;
    use LazilyRefreshDatabase;
    use SignInAssertions;

    /**
     * The app's session storage, which outlives each request's session store.
     */
    protected ArraySessionHandler $sessions;

    /**
     * Keep every request's session in one storage the test holds.
     */
    protected function setUpInteractsWithKeystone(): void
    {
        $sessions = $this->sessions = new ArraySessionHandler($this->app['config']->get('session.lifetime'));

        // The manager binds the callback to itself, so it can't reach the test through $this.
        $this->app['session']->extend('keystone-app-tests', fn () => $sessions);
        $this->app['config']->set('session.driver', 'keystone-app-tests');
    }

    /**
     * Create a user from the app's own factory.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createUser(array $attributes = []): KeystoneUser
    {
        return Keystone::userModel()::factory()->create($attributes);
    }

    /**
     * Get the test support of the first installed type that serves the surface.
     */
    public function supportFor(Surface $surface): CredentialTypeSupport
    {
        return $this->supportsFor($surface)[0];
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
     * Send the request from the browser, starting the app's request state afresh.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null): KeystoneTestResponse
    {
        $this->startRequest();

        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);

        $this->keepCookies($response);

        return KeystoneTestResponse::capture($response, $this->app['session.store']);
    }

    /**
     * Forget the guards and the session store an earlier request left in memory.
     */
    protected function startRequest(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }

    /**
     * Keep the cookies the response set for the browser's next request, and drop the ones it cleared.
     */
    protected function keepCookies(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->defaultCookies[$cookie->getName()]);

                continue;
            }

            $this->defaultCookies[$cookie->getName()] = $response->getCookie($cookie->getName())->getValue();
        }
    }
}
