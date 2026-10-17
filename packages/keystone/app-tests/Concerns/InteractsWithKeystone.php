<?php

namespace ClaudioDekker\Keystone\AppTests\Concerns;

use ClaudioDekker\Keystone\Accounts\EmailAddress;
use ClaudioDekker\Keystone\AppTests\Assertions\GuestStepAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignInAssertions;
use ClaudioDekker\Keystone\AppTests\Assertions\SignOutAssertions;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\Models\UserEmail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Drive the app the way a user does, and check its answers through assertions the app can override.
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
