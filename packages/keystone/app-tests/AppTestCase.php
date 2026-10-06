<?php

namespace ClaudioDekker\Keystone\AppTests;

use Carbon\CarbonInterval;
use ClaudioDekker\Keystone\AccountChange;
use ClaudioDekker\Keystone\AccountChanges;
use ClaudioDekker\Keystone\AppTests\Support\CredentialTypeSupport;
use ClaudioDekker\Keystone\CredentialAttempt;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\KeystoneUser;
use ClaudioDekker\Keystone\KnownDevices;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use ClaudioDekker\Keystone\RecoveryCodes;
use ClaudioDekker\Keystone\SignInDecision;
use Closure;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * @api
 */
abstract class AppTestCase extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * The namespace the app's own copies of the assertion traits live in.
     */
    public const string ASSERTIONS_NAMESPACE = 'Tests\\Keystone\\Assertions\\';

    /**
     * The headers of the hardening floor, besides Cache-Control, X-Frame-Options and Content-Security-Policy.
     */
    protected const array HARDENING_HEADERS = [
        'Pragma' => 'no-cache',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Cross-Origin-Opener-Policy' => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ];

    /**
     * Set up the test environment, relaxing the timing floor and sending a same-origin browser's headers.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Http::preventStrayRequests();
        $this->countLimitsInMemory();

        $this->withHeader('Sec-Fetch-Site', 'same-origin');
    }

    /**
     * Count rate limits in a fresh in-memory store, so no count outlives the test.
     */
    protected function countLimitsInMemory(): void
    {
        config(['cache.limiter' => 'array']);

        $this->app->forgetInstance(CacheRateLimiter::class);
    }

    /**
     * Call the given URI as a fresh request, forgetting what the guard resolved.
     *
     * @param  string  $method
     * @param  string  $uri
     * @param  array<string, mixed>  $parameters
     * @param  array<string, string>  $cookies
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $server
     * @param  string|null  $content
     * @return TestResponse<Response>
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        Auth::forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /**
     * Send the session cookie along, as the browser that holds the session would.
     *
     * @return array<string, string>
     */
    protected function prepareCookiesForRequest()
    {
        $this->defaultCookies[(string) config('session.cookie')] = $this->app['session.store']->getId();

        return parent::prepareCookiesForRequest();
    }

    /**
     * Send the device cookie on every later request, as the browser holding it would, or none, as a browser that never signed in.
     */
    public function fromDevice(?string $value): static
    {
        unset($this->unencryptedCookies[KnownDevices::COOKIE]);

        return $value === null ? $this : $this->withUnencryptedCookie(KnownDevices::COOKIE, $value);
    }

    /**
     * Get the value of the device cookie the response handed the browser.
     *
     * @param  TestResponse<Response>  $response
     */
    public function deviceCookieOf(TestResponse $response): ?string
    {
        return $response->getCookie(KnownDevices::COOKIE, decrypt: false)?->getValue();
    }

    /**
     * Get the factory of the keystone guard's user model, failing clearly when it has none.
     *
     * @return Factory<Model&KeystoneUser>
     */
    public function userFactory(): Factory
    {
        $model = Keystone::guard()->userModel()::class;

        if (! method_exists($model, 'factory')) {
            $this->fail("Keystone's AppTests create users with {$model}::factory(). Add the HasFactory trait and a factory to {$model}.");
        }

        return $model::factory();
    }

    /**
     * Create an account holding the address.
     *
     * @return Model&KeystoneUser
     */
    protected function createAccount(string $address = 'jane@example.com', bool $verified = true): Model
    {
        $account = $this->userFactory()->create();

        $this->holdAddress($account, $address, $verified);

        /** @var Model&KeystoneUser */
        return $account->newQueryWithoutScopes()->findOrFail($account->getKey());
    }

    /**
     * Put the address on the account.
     */
    protected function holdAddress(Model&KeystoneUser $account, string $address, bool $verified = true): void
    {
        $account->getConnection()->table('user_emails')->insert([
            'user_id' => $account->getKey(),
            'address' => $address,
            'verified_at' => $verified ? now() : null,
            'is_primary' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Arrange a credential of the supported type on the account.
     */
    protected function arrangeCredential(Model&KeystoneUser $account, CredentialTypeSupport $support, Surface $surface): void
    {
        $type = $this->types()->find($support->type(), $surface)
            ?? $this->fail("The [{$support->type()}] credential type doesn't serve {$surface->value}.");
        $arranged = $support->arrange($surface);

        $changes = new AccountChanges(Keystone::guard());

        $changes->change($account, function (AccountChange $change) use ($type, $arranged) {
            return $change->addCredential($type, identifier: $arranged['identifier'], secret: $arranged['secret'], label: $arranged['label']);
        });
    }

    /**
     * Give the account the first of a new set of recovery codes, all of them by default, returning them as shown to its user.
     *
     * @return list<string>
     */
    protected function arrangeRecoveryCodes(Model&KeystoneUser $account, int $count = RecoveryCodes::SET_SIZE): array
    {
        $codes = array_slice((new RecoveryCodes($account))->generate(), 0, $count);

        $changes = new AccountChanges(Keystone::guard());

        $changes->change($account, function (AccountChange $change) use ($codes) {
            (new RecoveryCodes($change->account))->replace($change->account->getKey(), $codes);
        });

        return $codes;
    }

    /**
     * Let an account with only a first factor sign in, as an app that requires neither a second factor nor recovery codes does.
     */
    protected function withoutMandates(): void
    {
        config([
            'keystone.require_second_factor' => false,
            'keystone.require_recovery_codes' => false,
        ]);
    }

    /**
     * Require every account to hold a second factor and recovery codes, as Keystone does by default.
     */
    protected function withMandates(): void
    {
        config([
            'keystone.require_second_factor' => true,
            'keystone.require_recovery_codes' => true,
        ]);
    }

    /**
     * Get what core keeps of the type's running enrollment ceremony in the session.
     */
    protected function enrollmentCeremony(string $type): mixed
    {
        $running = Keystone::guard()->slots()->get($type, Surface::ENROLLMENT->value);

        return is_array($running) ? $running['ceremony'] ?? null : null;
    }

    /**
     * Get the recovery codes staged in the session for the account to save.
     *
     * @return list<string>
     */
    protected function stagedRecoveryCodes(): array
    {
        $staged = Keystone::guard()->slots()->get(CredentialTypes::RECOVERY_CODE, Surface::ENROLLMENT->value);

        return is_array($staged) ? array_values($staged) : [];
    }

    /**
     * Submit the proof of the supported type for the identifier to the sign-in.
     *
     * @param  array<string, mixed>  $proof
     * @return TestResponse<Response>
     */
    public function submitSignIn(CredentialTypeSupport $support, string $identifier, array $proof): TestResponse
    {
        return $this->post(route('login.submit', ['type' => $support->type()]), [
            'identifier' => $identifier,
            ...$proof,
        ]);
    }

    /**
     * Create an account holding a credential of the supported type, and sign it in.
     *
     * @return Model&KeystoneUser
     */
    public function signInAccount(CredentialTypeSupport $support, string $address = 'jane@example.com'): Model
    {
        $account = $this->createAccount($address);
        $this->arrangeCredential($account, $support, Surface::SIGN_IN);

        $this->submitSignIn($support, $address, $support->validProof(Surface::SIGN_IN));

        return $account;
    }

    /**
     * Create an account holding a first factor and a second factor of the supported challenge type.
     *
     * @return Model&KeystoneUser
     */
    public function createChallengedAccount(CredentialTypeSupport $challenge, string $address = 'jane@example.com'): Model
    {
        $account = $this->createAccount($address);

        $this->arrangeCredential($account, $this->supportsFor(Surface::SIGN_IN)[0], Surface::SIGN_IN);
        $this->arrangeCredential($account, $challenge, Surface::CHALLENGE);

        return $account;
    }

    /**
     * Create an account holding only a first factor, which owes a second factor while the app requires one.
     *
     * @return Model&KeystoneUser
     */
    public function createFirstFactorAccount(string $address = 'jane@example.com'): Model
    {
        $account = $this->createAccount($address);

        $this->arrangeCredential($account, $this->supportsFor(Surface::SIGN_IN)[0], Surface::SIGN_IN);

        return $account;
    }

    /**
     * Start the supported type's enrollment ceremony for the held sign-in, then answer it.
     *
     * @return TestResponse<Response>
     */
    public function enrollSecondFactor(CredentialTypeSupport $support): TestResponse
    {
        $this->get(route('login.enrollment.start', ['type' => $support->type()]));

        return $this->post(route('login.enrollment.submit', ['type' => $support->type()]), $support->validEnrollment($this->enrollmentCeremony($support->type())));
    }

    /**
     * Pass the account's first factor, so its sign-in is held at the challenge.
     *
     * @return TestResponse<Response>
     */
    public function passFirstFactor(string $address = 'jane@example.com'): TestResponse
    {
        $support = $this->supportsFor(Surface::SIGN_IN)[0];

        return $this->submitSignIn($support, $address, $support->validProof(Surface::SIGN_IN));
    }

    /**
     * Run the sweep of abandoned challenges that core schedules, and nothing else on the app's schedule.
     */
    public function sweepAbandonedChallenges(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());

        $events->sole(fn (Event $event) => $event->description === 'keystone:sweep-abandoned-challenges')->run($this->app);
    }

    /**
     * Get the test support of every installed type serving the surface, skipping when there is none.
     *
     * @return non-empty-list<CredentialTypeSupport>
     */
    protected function supportsFor(Surface $surface): array
    {
        $supports = array_map($this->supportFor(...), $this->types()->serving($surface));

        if ($supports === []) {
            $this->markTestSkipped("No installed credential type serves {$surface->value}.");
        }

        return $supports;
    }

    /**
     * Get the type's test support: the one bound for it, else the one in its package's AppTests.
     */
    protected function supportFor(CredentialType $type): CredentialTypeSupport
    {
        $key = "keystone.test-support.{$type->name()}";
        $conventional = Str::beforeLast($type::class, '\\').'\\AppTests\\Support\\'.class_basename($type).'Support';

        return match (true) {
            $this->app->bound($key) => $this->app->make($key),
            class_exists($conventional) => $this->app->make($conventional),
            default => $this->fail("No test support for the [{$type->name()}] credential type: add {$conventional}, or bind one under [{$key}]."),
        };
    }

    /**
     * Get the test support of every listed type an account can enroll as its second factor, skipping when there is none.
     *
     * @return non-empty-list<CredentialTypeSupport>
     */
    protected function enrollmentSupports(): array
    {
        $offer = (new SignInDecision)->enrollmentOffer($this->types());
        $supports = array_map($this->supportFor(...), $offer);

        if ($supports === []) {
            $this->markTestSkipped('No installed credential type can be enrolled as a second factor.');
        }

        return $supports;
    }

    /**
     * Run the scenario once for every installed type serving the surface, each in a fresh session.
     *
     * @param  Closure(CredentialTypeSupport): void  $scenario
     */
    protected function eachSupportFor(Surface $surface, Closure $scenario): void
    {
        $this->eachOf($this->supportsFor($surface), $scenario);
    }

    /**
     * Run the scenario once for every listed type an account can enroll as its second factor, each in a fresh session.
     *
     * @param  Closure(CredentialTypeSupport): void  $scenario
     */
    protected function eachEnrollmentSupport(Closure $scenario): void
    {
        $this->eachOf($this->enrollmentSupports(), $scenario);
    }

    /**
     * Run the scenario once for each of the supports, each in a fresh session.
     *
     * @param  list<CredentialTypeSupport>  $supports
     * @param  Closure(CredentialTypeSupport): void  $scenario
     */
    protected function eachOf(array $supports, Closure $scenario): void
    {
        foreach ($supports as $support) {
            $this->app['session.store']->invalidate();
            Auth::forgetGuards();

            $scenario($support);
        }
    }

    /**
     * Get the app's copy of the assertion trait when it has one, else Keystone's.
     *
     * The app's copy uses Keystone's trait, directly or through its frontend adapter's, and redefines the assertions its responses need.
     *
     * @param  trait-string  $trait
     * @return trait-string
     */
    public static function assertions(string $trait): string
    {
        $override = self::ASSERTIONS_NAMESPACE.class_basename($trait);

        if (! trait_exists($override)) {
            return $trait;
        }

        if (! in_array($trait, trait_uses_recursive($override), true)) {
            throw new LogicException("{$override} must use {$trait}.");
        }

        return $override;
    }

    /**
     * Assert the response carries the hardening floor core attaches to every Keystone response, framed only as the app allows.
     *
     * @param  TestResponse<Response>  $response
     */
    public function assertHardeningFloor(TestResponse $response): void
    {
        $headers = $response->headers;
        $cacheControl = array_map($headers->getCacheControlDirective(...), ['no-store', 'max-age', 'must-revalidate']);
        $frameAncestors = (array) config('keystone.hardening.frame_ancestors');
        $expectedHeaders = [...self::HARDENING_HEADERS, 'X-Frame-Options' => $frameAncestors === [] ? 'DENY' : null];
        $expectedDirectives = [
            'object-src' => "'none'",
            'base-uri' => "'none'",
            'frame-ancestors' => $frameAncestors === [] ? "'none'" : implode(' ', $frameAncestors),
        ];

        $this->assertSame([true, '0', true], $cacheControl, 'The response may be cached.');

        foreach ($expectedHeaders as $name => $value) {
            $this->assertSame($value, $headers->get($name), "The response's {$name} header isn't the floor's.");
        }

        $policies = $headers->all('Content-Security-Policy');

        $this->assertNotEmpty($policies, 'The response has no Content-Security-Policy.');

        foreach ($policies as $policy) {
            $directives = $this->policyDirectives((string) $policy);

            foreach ($expectedDirectives as $name => $value) {
                $this->assertSame($value, $directives[$name] ?? null, "The response's Content-Security-Policy doesn't force {$name} to {$value}.");
            }
        }
    }

    /**
     * Get a policy's directives by name, keeping the first of each as a browser does.
     *
     * @return array<string, string>
     */
    protected function policyDirectives(string $policy): array
    {
        $directives = [];

        foreach (explode(';', $policy) as $directive) {
            $parts = preg_split('/\s+/', trim($directive), 2) ?: [''];

            $directives[strtolower($parts[0])] ??= $parts[1] ?? '';
        }

        return $directives;
    }

    /**
     * Assert the request waited out the timing floor, returning its response.
     *
     * Core sleeps for what the floor leaves after the request's own work, so the time slept and the time the whole request took reach the floor together.
     *
     * @param  Closure(): TestResponse<Response>  $request
     * @return TestResponse<Response>
     */
    protected function assertWaitsOutTimingFloor(Closure $request): TestResponse
    {
        $slept = [];

        Sleep::fake();
        Sleep::whenFakingSleep(function (CarbonInterval $duration) use (&$slept) {
            $slept[] = $duration->totalMicroseconds;
        });

        $started = hrtime(true);
        $response = $request();
        $tookMicroseconds = (hrtime(true) - $started) / 1_000;

        $this->assertNotEmpty($slept, 'The request returned without waiting out the timing floor.');
        $this->assertGreaterThanOrEqual(
            CredentialAttempt::TIMING_FLOOR_MICROSECONDS,
            array_sum($slept) + $tookMicroseconds,
            'The request waited less than the timing floor.',
        );

        return $response;
    }

    /**
     * Assert that two requests, one naming an existing account and one a missing one, answer alike.
     *
     * @param  Closure(): TestResponse<Response>  $existing
     * @param  Closure(): TestResponse<Response>  $missing
     */
    protected function assertIndistinguishable(Closure $existing, Closure $missing): void
    {
        $this->assertSame($this->observable($existing()), $this->observable($missing()), 'The responses differ.');
    }

    /**
     * Get what a client or a later request can observe of the response.
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, mixed>
     */
    protected function observable(TestResponse $response): array
    {
        $headers = array_diff(array_keys($response->headers->all()), ['date', 'set-cookie']);
        $cookies = array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
        sort($headers);
        sort($cookies);

        return [
            'status' => $response->getStatusCode(),
            'location' => $response->headers->get('Location'),
            'headers' => array_values($headers),
            'cookies' => $cookies,
            'flashed' => $this->flashed(),
        ];
    }

    /**
     * Get the data the last request flashed, with typed input reduced to its field names.
     *
     * @return array<string, mixed>
     */
    protected function flashed(): array
    {
        $session = $this->app['session.store'];
        $flashed = [];

        // Saving the session at the end of the request ages what it flashed.
        foreach ($session->get('_flash.old', []) as $key) {
            $value = $session->get($key);

            $flashed[$key] = match (true) {
                $value instanceof ViewErrorBag => $value->getBag('default')->getMessages(),
                $key === '_old_input' => array_keys($value),
                default => $value,
            };
        }

        ksort($flashed);

        return $flashed;
    }

    /**
     * Get the registered credential types.
     */
    protected function types(): CredentialTypes
    {
        return $this->app->make(CredentialTypes::class);
    }
}
