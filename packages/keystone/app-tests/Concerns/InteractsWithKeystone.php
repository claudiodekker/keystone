<?php

namespace ClaudioDekker\Keystone\AppTests\Concerns;

use ClaudioDekker\Keystone\Accounts\EmailAddress;
use ClaudioDekker\Keystone\AppTests\Assertions\GuestStepAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignOutAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\ThrottledAssertions;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\AppTests\Support\RecordedSecurityEvents;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserEmail;
use ClaudioDekker\Keystone\RateLimits\StepKind;
use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Drive the app the way a user does, and check its answers through assertions the app can override.
 *
 * @api
 */
trait InteractsWithKeystone
{
    use AssertsHardening;
    use GuestStepAssertions;
    use LazilyRefreshDatabase;
    use SignInAssertions;
    use SignOutAssertions;
    use ThrottledAssertions;

    /**
     * The handler that keeps every line the security log channel wrote during the test.
     */
    protected TestHandler $securityLog;

    /**
     * Keep the lines the security log channel writes, and count rate limits in a store of this test's own.
     */
    protected function setUpInteractsWithKeystone(): void
    {
        $this->securityLog = new TestHandler;

        Log::channel(config('keystone.log_channel'))->pushHandler($this->securityLog);

        $this->countLimitsIn(Cache::repository(new ArrayStore));
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
     * Get how many failed attempts an account gets per credential type and flow in an hour, as the app configures it.
     */
    public function failedAttemptAllowance(): int
    {
        return config()->integer('keystone.rate_limits.failed_attempts_per_hour');
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
        return collect(Keystone::credentialTypes()->serving($surface))
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
