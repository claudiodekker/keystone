<?php

namespace ClaudioDekker\Keystone\AppTests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * Carry the cookies each response sets into the test's next request.
 *
 * @api
 */
trait InteractsWithCookies
{
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
     * Keep the cookies the response set for the next request.
     */
    protected function keepCookies(TestResponse $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            $this->defaultCookies[$cookie->getName()] = $response->getCookie($cookie->getName())->getValue();
        }
    }
}
