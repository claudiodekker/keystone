<?php

namespace ClaudioDekker\Keystone\AppTests\Assertions;

use Illuminate\Testing\TestResponse;

/**
 * @api
 */
trait SudoAssertions
{
    /**
     * Assert the response sent the user whose session holds no sudo to the sudo page.
     */
    public function assertSudoRequired(TestResponse $response): void
    {
        $response->assertRedirectToRoute('sudo');
    }

    /**
     * Assert the response told a JSON client the request needs sudo.
     */
    public function assertSudoRequiredForJson(TestResponse $response): void
    {
        $response->assertForbidden();
        $response->assertJsonPath('reason', 'sudo');
        $response->assertJsonPath('message', __('keystone::messages.sudo-required'));
    }

    /**
     * Assert the response shows the sudo page with the given page fields.
     *
     * @param  array<string, mixed>  $fields
     */
    public function assertSudoPage(TestResponse $response, array $fields = []): void
    {
        $response->assertOk();

        foreach ($fields as $name => $value) {
            $response->assertViewHas($name, $value);
        }
    }

    /**
     * Assert the response sent the user on to the given path once sudo was granted.
     */
    public function assertSudoGranted(TestResponse $response, string $redirectTo): void
    {
        $response->assertRedirect($redirectTo);
    }

    /**
     * Assert the response sent a session that holds no sudo-in-progress on to the given path.
     */
    public function assertNoSudoInProgress(TestResponse $response, string $redirectTo): void
    {
        $response->assertRedirect($redirectTo);
    }
}
