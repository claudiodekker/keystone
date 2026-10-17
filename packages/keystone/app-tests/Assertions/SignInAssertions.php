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

        Assert::assertArrayHasKey('identifier', $response->oldInput(), 'The response flashed back no identifier.');

        $this->assertFlashedOnlyTheIdentifier($response);
    }

    /**
     * Assert the response refused the submit as invalid on the given fields, flashing back nothing but the identifier.
     *
     * @param  array<int|string, string>  $fields  Field names, or a field's expected message by its name.
     */
    public function assertSignInInvalid(KeystoneTestResponse $response, array $fields): void
    {
        $response->assertInvalid($fields);

        $this->assertFlashedOnlyTheIdentifier($response);
    }

    /**
     * Assert the response answered a credential type the sign-in page doesn't offer as not found.
     */
    public function assertSignInTypeNotFound(KeystoneTestResponse $response): void
    {
        $response->assertNotFound();
    }

    /**
     * Assert the response sent the signed-in user on to the given path.
     */
    public function assertSignedIn(KeystoneTestResponse $response, string $redirectTo): void
    {
        $response->assertRedirect($redirectTo);
    }

    /**
     * Assert the response flashed back no input but the identifier.
     */
    protected function assertFlashedOnlyTheIdentifier(KeystoneTestResponse $response): void
    {
        $others = array_diff(array_keys($response->oldInput()), ['identifier']);

        Assert::assertSame([], array_values($others), 'The response flashed back more than the identifier.');
    }
}
