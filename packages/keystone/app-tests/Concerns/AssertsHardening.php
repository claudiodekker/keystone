<?php

namespace ClaudioDekker\Keystone\AppTests\Concerns;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Check the hardening floor and Clear-Site-Data on a response, which Keystone sets whatever the app's controllers answer.
 *
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
