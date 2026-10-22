<?php

use ClaudioDekker\Keystone\Lint\Checks\ResponsesThroughAssertionTraits;
use ClaudioDekker\LaravelGuardrails\Kind;
use ClaudioDekker\LaravelGuardrails\Testing\TestFile;

it('fails an assertion called on a response', function () {
    $file = TestFile::of(Kind::TESTS, 'packages/keystone/app-tests/Flows/ExampleTest.php', <<<'PHP'
        <?php

        it('signs in', function () {
            $response = $this->post(route('login.submit', 'form'));

            $response->assertRedirect('/');
            $response?->assertSessionHasErrors('identifier');
        });
        PHP);

    expect($file->lines(new ResponsesThroughAssertionTraits))->toBe([6, 7]);
});

it('fails an assertion chained onto a request', function () {
    $file = TestFile::of(Kind::TESTS, 'packages/keystone/app-tests/Flows/ExampleTest.php', <<<'PHP'
        <?php

        it('signs in', function () {
            $this->post(route('login.submit', 'form'))->assertRedirect('/');
        });
        PHP);

    expect($file->lines(new ResponsesThroughAssertionTraits))->toBe([4]);
});

it('passes assertions called on the test case and on facades', function () {
    $file = TestFile::of(Kind::TESTS, 'packages/keystone/app-tests/Flows/ExampleTest.php', <<<'PHP'
        <?php

        it('signs in', function () {
            $response = $this->post(route('login.submit', 'form'));

            $this->assertSignedIn($response, '/');
            Event::assertDispatched(Login::class);
        });
        PHP);

    expect($file->lines(new ResponsesThroughAssertionTraits))->toBe([]);
});

it('reads only the AppTests themselves', function (string $path) {
    $file = TestFile::of(Kind::TESTS, $path, "<?php\n\n\$response->assertRedirect('/');\n");

    expect($file->lines(new ResponsesThroughAssertionTraits))->toBe([]);
})->with([
    'packages/keystone/app-tests/Assertions/SignInAssertions.php',
    'packages/keystone/tests/Feature/ExampleTest.php',
]);
