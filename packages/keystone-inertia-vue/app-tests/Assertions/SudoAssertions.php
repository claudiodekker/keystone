<?php

namespace ClaudioDekker\Keystone\InertiaVue\AppTests\Assertions;

use ClaudioDekker\Keystone\AppTests\Assertions\SudoAssertions as KeystoneSudoAssertions;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * @api
 */
trait SudoAssertions
{
    use KeystoneSudoAssertions;

    /**
     * Assert the response is the sudo page, offering the types in order.
     *
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $types
     */
    public function assertSudoPage(TestResponse $response, array $types): void
    {
        $response->assertInertia(function (AssertableInertia $page) use ($types) {
            $page->component('auth/Sudo')
                ->where('types', fn ($offered) => collect($offered)->pluck('type')->all() === $types)
                ->where('preselect', $types[0] ?? null);
        });
    }
}
