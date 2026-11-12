<?php

namespace ClaudioDekker\Keystone\AppTests\Concerns;

use ClaudioDekker\Keystone\Accounts\EmailAddress;
use ClaudioDekker\Keystone\AppTests\Assertions\ChallengeAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\CrossSiteAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\EnrollmentAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\GuestStepAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SessionExpiryAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignOutAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SudoAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\ThrottledAssertions;
use ClaudioDekker\Keystone\AppTests\Support\Browser;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\AppTests\Support\RecordedSecurityEvents;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserCredential;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * @api
 */
trait InteractsWithKeystone
{
    use AssertsHardening;
    use ChallengeAssertions;
    use CrossSiteAssertions;
    use EnrollmentAssertions;
    use GuestStepAssertions;
    use LazilyRefreshDatabase;
    use SessionExpiryAssertions;
    use SignInAssertions;
    use SignOutAssertions;
    use SudoAssertions;
    use ThrottledAssertions;

    /**
     * The handler that keeps every line the security log channel wrote during the test.
     */
    protected TestHandler $securityLog;

    /**
     * Keep the lines the security log channel writes, count rate limits in a store of this test's own, and send what a browser sends from the app's own pages.
     */
    protected function setUpInteractsWithKeystone(): void
    {
        $this->securityLog = new TestHandler;

        Log::channel(config('keystone.log_channel'))->pushHandler($this->securityLog);

        $this->countLimitsIn(Cache::repository(new ArrayStore));

        $this->withHeader('Sec-Fetch-Site', 'same-origin');
    }

    /**
     * Send the requests from here on as a page on another site would, with that site's origin.
     */
    public function fromAnotherSite(): static
    {
        return $this->withHeaders([
            'Origin' => 'https://elsewhere.example',
            'Sec-Fetch-Site' => 'cross-site',
        ]);
    }

    /**
     * Send the requests from here on from a browser that has never visited the app, and get the browser left behind.
     */
    public function switchToFreshBrowser(): Browser
    {
        return $this->switchTo(new Browser([]));
    }

    /**
     * Send the requests from here on from the browser, as it was when it was left.
     */
    public function switchBackTo(Browser $browser): void
    {
        $this->switchTo($browser);
    }

    /**
     * Keep the cookies the response set in this browser, so the requests from here on send them, as a browser does.
     *
     * The session cookie is left out, because the test's session already carries over from one request to the next.
     */
    public function keepCookiesFrom(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $name = $cookie->getName();

            if ($name === config('session.cookie')) {
                continue;
            }

            $this->withUnencryptedCookie($name, (string) $cookie->getValue());
        }
    }

    /**
     * Get a fresh browser holding a copy of this browser's cookies and none of its session, as one who copied them would.
     */
    public function browserWithCopiedCookies(): Browser
    {
        return new Browser([], $this->unencryptedCookies);
    }

    /**
     * Put the browser's session and cookies in place of the current ones, and get the browser that held the current ones.
     */
    protected function switchTo(Browser $browser): Browser
    {
        $session = $this->app['session'];
        $left = new Browser($session->all(), $this->unencryptedCookies);

        Auth::forgetGuards();
        $session->flush();
        $session->put($browser->session);

        $this->unencryptedCookies = $browser->cookies;

        return $left;
    }

    /**
     * Count rate limits in the given store for the rest of the test, whatever store the app limits in.
     */
    public function countLimitsIn(Repository $store): void
    {
        $this->app->instance(RateLimiter::class, new RateLimiter($store));
    }

    /**
     * Have the app put the headers on every response it gives, as an app's own global middleware does.
     *
     * @param  array<string, string|list<string>>  $headers
     */
    public function appSetsHeaders(array $headers): void
    {
        $this->app->make(Kernel::class)->pushMiddleware(function (Request $request, Closure $next) use ($headers) {
            $response = $next($request);

            foreach ($headers as $name => $values) {
                $response->headers->set($name, $values);
            }

            return $response;
        });
    }

    /**
     * Serve a page only a signed-in user reaches, through the app's own auth middleware, and get its path.
     */
    public function protectedPage(): string
    {
        $path = '/keystone/app-tests/protected';

        Route::middleware(['web', 'auth'])->get($path, fn () => response()->noContent());

        return $path;
    }

    /**
     * Serve a page only a signed-in user holding sudo reaches, through the app's own auth and sudo middleware, and get its path.
     */
    public function sudoGatedPage(): string
    {
        $path = '/keystone/app-tests/sudo-gated';

        Route::middleware(['web', 'auth', 'sudo'])->match(['get', 'post'], $path, fn () => response()->noContent());

        return $path;
    }

    /**
     * Get how many failed attempts an account gets per credential type and flow in an hour, as the app configures it.
     */
    public function failedAttemptAllowance(): int
    {
        return config()->integer('keystone.rate_limits.failed_attempts_per_hour');
    }

    /**
     * Get how many seconds a session lasts after its sign-in as the app configures it, skipping the test when the app turned the lifetime off.
     */
    public function absoluteLifetimeSeconds(): int
    {
        $lifetime = config('keystone.session.absolute_lifetime_seconds');

        if (! is_int($lifetime)) {
            $this->markTestSkipped('The app turned the absolute session lifetime off.');
        }

        return $lifetime;
    }

    /**
     * Get how many seconds sudo lasts after it was granted, as the app configures it.
     */
    public function sudoLifetimeSeconds(): int
    {
        return config()->integer('keystone.sudo.lifetime_seconds');
    }

    /**
     * Get how many seconds a remember-me cookie restores for after its sign-in as the app configures it, skipping the test when the app turned remember me off.
     */
    public function rememberLifetimeSeconds(): int
    {
        $lifetime = config()->integer('keystone.remember.lifetime_seconds');

        if ($lifetime === 0) {
            $this->markTestSkipped('The app turned remember me off.');
        }

        return $lifetime;
    }

    /**
     * Get how many requests of the step kind one IP address or one signed-in account gets in a minute, as the app configures it.
     */
    public function requestAllowance(StepKind $kind): int
    {
        return config()->integer('keystone.rate_limits.requests_per_minute.'.$kind->value);
    }

    /**
     * Submit the type's rejected proof for the identifier the given number of times, each from its own address, so only the failed-attempt limit is spent.
     */
    public function submitRejectedProofs(CredentialTypeSupport $support, string $identifier, int $times): void
    {
        foreach (range(1, $times) as $i) {
            $address = '10.'.intdiv($i, 256).'.'.($i % 256).'.1';

            $this->withServerVariables(['REMOTE_ADDR' => $address])->post(route('login.submit', $support->type()->name()), [
                'identifier' => $identifier,
                ...$support->rejectedProof(Surface::SIGN_IN),
            ]);
        }
    }

    /**
     * Turn the second-factor and recovery-code mandates off for the rest of the test, so an account holding neither signs in on its first factor alone.
     */
    public function withoutEnrollmentMandates(): void
    {
        config([
            'keystone.require_recovery_codes' => false,
            'keystone.require_second_factor' => false,
        ]);
    }

    /**
     * Give the user a verified address and a credential of the first installed type that serves sign-in, and submit its valid proof.
     */
    public function passFirstFactor(KeystoneUser $user): TestResponse
    {
        $address = $this->addVerifiedAddress($user);
        $support = $this->supportFor(Surface::SIGN_IN)->arrangeCredential(Surface::SIGN_IN, $user);

        return $this->post(route('login.submit', $support->type()->name()), [
            'identifier' => $address,
            ...$support->validProof(Surface::SIGN_IN, $user),
        ]);
    }

    /**
     * Sign the user in through the first installed type that serves sign-in, then let the sudo grant the sign-in brought run out.
     */
    public function signInWithoutSudo(KeystoneUser $user): void
    {
        $this->passFirstFactor($user);

        Auth::forgetGuards();
        $this->travel($this->sudoLifetimeSeconds())->seconds();
    }

    /**
     * Submit the valid proof of the user's credential of the supported type to the challenge.
     */
    public function answerChallenge(KeystoneUser $user, CredentialTypeSupport $support): TestResponse
    {
        return $this->post(route('login.challenge.submit', $support->type()->name()), $support->validProof(Surface::CHALLENGE, $user));
    }

    /**
     * Remove the user's credentials of the supported type, as if the user had removed them.
     */
    public function removeCredentials(KeystoneUser $user, CredentialTypeSupport $support): void
    {
        UserCredential::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->where('type', $support->type()->name())
            ->delete();
    }

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
     * Assert the user holds the address verified, as Keystone reads it.
     */
    public function assertAddressVerified(KeystoneUser $user, string $address): void
    {
        $held = UserEmail::query()
            ->whereVerified(EmailAddress::normalize($address))
            ->where('user_id', $user->getAuthIdentifier())
            ->exists();

        Assert::assertTrue($held, "The user holds no verified [{$address}].");
    }

    /**
     * Assert the user holds the address unverified, as Keystone reads it.
     */
    public function assertAddressUnverified(KeystoneUser $user, string $address): void
    {
        $held = UserEmail::query()
            ->where('address', EmailAddress::normalize($address))
            ->whereNull('verified_address')
            ->where('user_id', $user->getAuthIdentifier())
            ->exists();

        Assert::assertTrue($held, "The user holds no unverified [{$address}].");
    }

    /**
     * Get what Keystone recorded during the test: the stored security events and the security event log lines.
     */
    public function securityEvents(): RecordedSecurityEvents
    {
        return new RecordedSecurityEvents($this->securityLog);
    }

    /**
     * Get every alert sent while notifications were faked, each with the address it went to.
     *
     * @return Collection<int, array{address: string, alert: Notification}>
     */
    public function sentAlerts(): Collection
    {
        $notifications = NotificationFacade::getFacadeRoot();

        if (! $notifications instanceof NotificationFake) {
            Assert::fail('Notifications are not faked. Call Notification::fake() before the alert is sent.');
        }

        $onDemand = $notifications->sentNotifications()[AnonymousNotifiable::class] ?? [];

        return collect($onDemand)
            ->flatten(2)
            ->map(fn (array $sent) => [
                'address' => $sent['notifiable']->routeNotificationFor('mail'),
                'alert' => $sent['notification'],
            ])
            ->values();
    }

    /**
     * Get the test support of the first installed type that serves the surface, naming the fix when none does.
     */
    public function supportFor(Surface $surface): CredentialTypeSupport
    {
        return $this->supportsFor($surface)[0] ?? Assert::fail("No installed credential type with test support serves [{$surface->value}]. Install a method package that does.");
    }

    /**
     * Get the test support of every installed type that serves the surface, in the order the types were registered.
     *
     * @return list<CredentialTypeSupport>
     */
    public function supportsFor(Surface $surface): array
    {
        return collect($this->app->make(CredentialTypes::class)->serving($surface))
            ->map(fn (CredentialType $type) => $this->supportOf($type))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Get the type's test support: the type itself when it is its own, else the support named after it in its package's AppTests.
     */
    protected function supportOf(CredentialType $type): ?CredentialTypeSupport
    {
        if ($type instanceof CredentialTypeSupport) {
            return $type;
        }

        $support = $this->supportClassOf($type);

        return is_subclass_of($support, CredentialTypeSupport::class) ? new $support : null;
    }

    /**
     * Get the name of the type's test support class, as PasswordType has AppTests\PasswordSupport beside it.
     */
    protected function supportClassOf(CredentialType $type): string
    {
        $namespace = Str::beforeLast($type::class, '\\');
        $name = Str::chopEnd(class_basename($type), 'Type');

        return "{$namespace}\\AppTests\\{$name}Support";
    }

    /**
     * Assert the two responses match in status, redirect, and header and cookie names.
     */
    public function assertIndistinguishable(TestResponse $existing, TestResponse $missing): void
    {
        Assert::assertSame($existing->getStatusCode(), $missing->getStatusCode(), 'The responses differ in status.');
        Assert::assertSame($existing->headers->get('Location'), $missing->headers->get('Location'), 'The responses differ in redirect.');
        Assert::assertSame($this->headerNames($existing), $this->headerNames($missing), 'The responses differ in header names.');
        Assert::assertSame($this->cookieNames($existing), $this->cookieNames($missing), 'The responses differ in Set-Cookie names.');
    }

    /**
     * Get the response's header names, lowercase and sorted.
     *
     * @return list<string>
     */
    protected function headerNames(TestResponse $response): array
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
    protected function cookieNames(TestResponse $response): array
    {
        return collect($response->headers->getCookies())
            ->map(fn (Cookie $cookie) => $cookie->getName())
            ->sort()
            ->values()
            ->all();
    }
}
