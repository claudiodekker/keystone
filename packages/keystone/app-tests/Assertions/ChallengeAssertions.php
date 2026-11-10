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
     * Assert the response refused the account's last recovery code with its message, back at the challenge and flashing back no input.
     */
    public function assertLastRecoveryCodeKept(TestResponse $response): void
    {
        $response->assertRedirectToRoute('login.challenge');
        $response->assertSessionHasErrors(['recovery_code' => __('keystone::messages.last-recovery-code')]);

        Assert::assertSame([], $this->app['session.store']->getOldInput(), 'The response flashed back the code.');
    }

    /**
     * Assert the response refused the answer as invalid on the given fields, flashing back no input.
     *
     * @param  array<int|string, string>  $fields  Field names, or a field's expected message by its name.
     */
    public function assertChallengeInvalid(TestResponse $response, array $fields): void
    {
        $response->assertInvalid($fields);

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
