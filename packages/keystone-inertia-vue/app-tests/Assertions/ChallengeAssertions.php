<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\ChallengeAssertions as KeystoneChallengeAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait ChallengeAssertions
{
    use KeystoneChallengeAssertions;

    /**
     * Assert the response is the challenge page, offering the types in order.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $types
     */
    public function assertChallengePage(TestResponse $response, array $types): void
    {
        $response->assertInertia(function (AssertableInertia $page) use ($types) {
            $page->component('auth/Challenge')
                ->where('types', fn ($offered) => collect($offered)->pluck('type')->all() === $types)
                ->where('preselect', $types[0] ?? null);
        });
    }
}
