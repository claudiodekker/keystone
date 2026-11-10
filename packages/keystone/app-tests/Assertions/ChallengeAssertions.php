<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * @api
 */
trait ChallengeAssertions
{
    /**
     * Assert the response shows the challenge page with the given page fields.
     *
     * @param  array<string, mixed>  $fields
     */
    public function assertChallengePage(TestResponse $response, array $fields = []): void
    {
        $response->assertOk();

        foreach ($fields as $name => $value) {
            $response->assertViewHas($name, $value);
        }
    }

    /**
     * Assert the response sent a session that holds no pending sign-in to sign in.
     */
    public function assertNoPendingSignIn(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login');
    }

    /**
     * Assert the response refused the answer with the message on the field, back at the challenge and flashing back no input.
     */
    public function assertChallengeRefused(TestResponse $response, string $field, string $message): void
    {
        $response->assertRedirectToRoute('login.challenge');
        $response->assertSessionHasErrors([$field => $message]);

        Assert::assertSame([], $this->app['session.store']->getOldInput(), 'The response flashed back the answer.');
    }

    /**
     * Assert the response sent the user to sign in again after cancelling the pending sign-in.
     */
    public function assertChallengeCancelled(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login');
    }
}
