<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Check the one refusal every spent rate limit gives, which each area's throttled assertion defaults to.
 *
 * @api
 */
trait ThrottledAssertions
{
    /**
     * Assert the response refused the request as throttled: too many requests, free again after a whole number of seconds.
     */
    protected function assertThrottled(TestResponse $response): void
    {
        $response->assertTooManyRequests();

        Assert::assertMatchesRegularExpression('/^[1-9][0-9]*$/', $response->headers->get('Retry-After', ''), 'The response gave no Retry-After of at least one second.');
    }
}
