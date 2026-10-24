<?php

namespace ClaudioDekker\Keystone\AppTests\Concerns;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * @api
 */
trait AssertsHardening
{
    /**
     * Assert the response carries the hardening floor: uncacheable, never framed, and closed to objects and a changed base in each policy.
     */
    public function assertHardened(TestResponse $response): void
    {
        $headers = $response->headers;

        Assert::assertTrue($headers->hasCacheControlDirective('no-store'), 'The response is not Cache-Control: no-store.');
        Assert::assertSame('0', $headers->getCacheControlDirective('max-age'), 'The response is not Cache-Control: max-age=0.');
        Assert::assertTrue($headers->hasCacheControlDirective('must-revalidate'), 'The response is not Cache-Control: must-revalidate.');
        Assert::assertFalse($headers->hasCacheControlDirective('public'), 'The response is Cache-Control: public.');
        Assert::assertFalse($headers->hasCacheControlDirective('s-maxage'), 'The response sets Cache-Control: s-maxage.');

        $response->assertHeader('Pragma', 'no-cache');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $response->assertHeader('X-Frame-Options', 'DENY');

        $this->assertPoliciesClose($response, ['object-src', 'base-uri', 'frame-ancestors']);
    }

    /**
     * Assert the response lets exactly the given sources frame it, with no X-Frame-Options.
     *
     * @param  list<string>  $sources
     */
    public function assertFramableBy(TestResponse $response, array $sources): void
    {
        Assert::assertFalse($response->headers->has('X-Frame-Options'), 'The response sets X-Frame-Options, which stops the sources framing it.');

        $policies = $this->contentSecurityPolicies($response);

        Assert::assertNotEmpty($policies, 'The response carries no Content-Security-Policy.');

        foreach ($policies as $policy) {
            Assert::assertSame(implode(' ', $sources), $policy['frame-ancestors'] ?? null, 'A Content-Security-Policy of the response lets other sources frame it.');
        }
    }

    /**
     * Assert the response carries exactly these policies, each given as its directives by name, in any order.
     *
     * @param  list<array<string, string>>  $expected
     */
    public function assertContentSecurityPolicies(TestResponse $response, array $expected): void
    {
        $policies = array_map($this->canonicalPolicy(...), $this->contentSecurityPolicies($response));
        $expected = array_map($this->canonicalPolicy(...), $expected);

        sort($policies);
        sort($expected);

        Assert::assertSame($expected, $policies, 'The response carries other Content-Security-Policy headers.');
    }

    /**
     * Assert the response tells the browser to clear exactly the given kinds of site data.
     *
     * @param  list<string>  $kinds
     */
    public function assertSiteDataCleared(TestResponse $response, array $kinds): void
    {
        $header = $response->headers->get('Clear-Site-Data');

        Assert::assertNotNull($header, 'The response does not clear any site data.');
        Assert::assertMatchesRegularExpression('/^"[A-Za-z]+"(, "[A-Za-z]+")*$/D', $header, 'The Clear-Site-Data header is not a list of quoted kinds.');

        preg_match_all('/"([A-Za-z]+)"/', $header, $matches);

        $cleared = $matches[1];
        sort($cleared);
        sort($kinds);

        Assert::assertSame($kinds, $cleared, 'The response clears other site data.');
    }

    /**
     * Assert the response tells the browser to clear no site data.
     */
    public function assertSiteDataKept(TestResponse $response): void
    {
        Assert::assertFalse($response->headers->has('Clear-Site-Data'), 'The response clears site data.');
    }

    /**
     * Assert the response carries a policy, and each policy it carries sets the directives to 'none'.
     *
     * @param  list<string>  $directives
     */
    protected function assertPoliciesClose(TestResponse $response, array $directives): void
    {
        $policies = $this->contentSecurityPolicies($response);

        Assert::assertNotEmpty($policies, 'The response carries no Content-Security-Policy.');

        foreach ($policies as $policy) {
            foreach ($directives as $directive) {
                Assert::assertSame("'none'", $policy[$directive] ?? null, "A Content-Security-Policy of the response does not close [{$directive}].");
            }
        }
    }

    /**
     * Get the policy's directives as one string that does not depend on their order.
     *
     * @param  array<string, string>  $directives
     */
    protected function canonicalPolicy(array $directives): string
    {
        ksort($directives);

        return json_encode($directives, JSON_THROW_ON_ERROR);
    }

    /**
     * Get each policy the response carries, as its directives by lowercase name.
     *
     * @return list<array<string, string>>
     */
    protected function contentSecurityPolicies(TestResponse $response): array
    {
        $policies = [];

        foreach ($response->headers->all('content-security-policy') as $header) {
            foreach (explode(',', $header) as $policy) {
                $policies[] = $this->directivesOf($policy);
            }
        }

        return $policies;
    }

    /**
     * Get the directives of one policy by lowercase name, the first of a repeated directive winning as a browser reads it.
     *
     * @return array<string, string>
     */
    protected function directivesOf(string $policy): array
    {
        $directives = [];

        foreach (explode(';', $policy) as $directive) {
            [$name, $value] = array_pad(preg_split('/\s+/', trim($directive), 2) ?: [], 2, '');

            if ($name === '') {
                continue;
            }

            $directives[strtolower($name)] ??= $value;
        }

        return $directives;
    }
}
