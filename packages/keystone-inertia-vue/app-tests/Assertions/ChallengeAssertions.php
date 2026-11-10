<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\ChallengeAssertions as KeystoneChallengeAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * @api
 */
trait ChallengeAssertions
{
    use KeystoneChallengeAssertions;

    /**
     * Assert the response shows the challenge page, the types and the preselected type each a prop of its own, with the given fields.
     *
     * @param  array<string, mixed>  $fields
     */
    public function assertChallengePage(TestResponse $response, array $fields = []): void
    {
        $response->assertOk();

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/Challenge')
            ->hasAll(['preselected', 'types'])
            ->whereAll($fields));

        PHPUnit::assertArrayHasKey('encryptHistory', $response->inertiaPage(), 'The browser history is not encrypted.');
    }
}
