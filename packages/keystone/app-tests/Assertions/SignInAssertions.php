<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\KeystoneTestResponse;
use PHPUnit\Framework\Assert;

/**
 * Check how the app answers each outcome of signing in, one method per response hook of the sign-in controller.
 *
 * @api
 */
trait SignInAssertions
{
    /**
     * Assert the response shows the sign-in page with the given page fields.
     *
     * @param  array<string, mixed>  $fields
     */
    public function assertSignInPage(KeystoneTestResponse $response, array $fields = []): void
    {
        $response->assertOk();

        foreach ($fields as $name => $value) {
            $response->assertViewHas($name, $value);
        }
    }

    /**
     * Assert the response refused the sign-in with the message on the identifier, flashing back only the identifier.
     */
    public function assertSignInRefused(KeystoneTestResponse $response, string $message): void
    {
        $response->assertRedirectToRoute('login');
        $response->assertSessionHasErrors(['identifier' => $message]);

        Assert::assertSame(['identifier'], array_keys($response->oldInput()), 'The response flashed back more than the identifier.');
    }

    /**
     * Assert the response sent the signed-in user on to the given path.
     */
    public function assertSignedIn(KeystoneTestResponse $response, string $redirectTo): void
    {
        $response->assertRedirect($redirectTo);
    }
}
