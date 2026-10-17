<?php

namespace ClaudioDekker\Keystone\AppTests;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A test response that keeps the session as its own request left it, and how long the request took on the test clock.
 *
 * Every request of an AppTest shares the app's one session store, so each
 * response keeps a copy of the session as its request left it. Reading
 * the store afterwards would show what the next request put there.
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
     * How long the request took on the test clock, in milliseconds.
     */
    protected float $milliseconds;

    /**
     * Wrap a test response with the session its request left behind and the time it took.
     */
    public static function capture(TestResponse $response, Session $session, float $milliseconds): static
    {
        $captured = new static($response->baseResponse, $response->baseRequest);

        $captured->session = $session;
        $captured->milliseconds = $milliseconds;

        return $captured->withExceptions($response->exceptions);
    }

    /**
     * Get the data the request flashed, by key, with each error bag as its messages.
     *
     * @return array<string, mixed>
     */
    public function flashed(): array
    {
        return collect($this->session->get('_flash.old', []))
            ->mapWithKeys(fn (string $key) => [$key => $this->session->get($key)])
            ->map(fn (mixed $value) => $value instanceof ViewErrorBag ? $this->messages($value) : $value)
            ->all();
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
     * Get how long the request took on the test clock, in milliseconds.
     */
    public function milliseconds(): float
    {
        return $this->milliseconds;
    }

    /**
     * Get the session as the request left it.
     */
    protected function session(): Session
    {
        return $this->session;
    }

    /**
     * Get the messages of every bag, by bag and field.
     *
     * @return array<string, array<string, list<string>>>
     */
    protected function messages(ViewErrorBag $errors): array
    {
        return collect($errors->getBags())
            ->map(fn (MessageBag $bag) => $bag->getMessages())
            ->all();
    }
}
