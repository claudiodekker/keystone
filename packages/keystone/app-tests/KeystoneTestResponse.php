<?php

namespace ClaudioDekker\Keystone\AppTests;

use Illuminate\Contracts\Session\Session;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A test response that keeps the session as its own request left it.
 *
 * Each request of an AppTest gets a session store of its own, so the response reads the session from
 * the store its request used, never from whatever store a later request built.
 *
 * @api
 *
 * @extends TestResponse<Response>
 */
class KeystoneTestResponse extends TestResponse
{
    /**
     * The session as the request left it.
     */
    protected Session $session;

    /**
     * Wrap a test response with the session its request left behind.
     */
    public static function capture(TestResponse $response, Session $session): static
    {
        $captured = new static($response->baseResponse, $response->baseRequest);

        $captured->session = $session;

        return $captured->withExceptions($response->exceptions);
    }

    /**
     * Get the input the request flashed back to the form.
     *
     * @return array<string, mixed>
     */
    public function oldInput(): array
    {
        return $this->session->get('_old_input', []);
    }

    /**
     * Get the session as the request left it.
     */
    protected function session(): Session
    {
        return $this->session;
    }
}
