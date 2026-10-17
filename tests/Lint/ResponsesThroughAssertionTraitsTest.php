<?php

use ClaudioDekker\Keystone\Lint\Checks\ResponsesThroughAssertionTraits;
use ClaudioDekker\Keystone\Lint\File;

it('fails an assertion called on a response', function () {
    $file = new File('packages/keystone/app-tests/Flows/ExampleTest.php', <<<'PHP'
        <?php

        it('signs in', function () {
            $response = $this->post(route('login.submit', 'form'));

            $response->assertRedirect('/');
            $response?->assertSessionHasErrors('identifier');
        });
        PHP);

    expect((new ResponsesThroughAssertionTraits)->check($file))->toBe([6, 7]);
});

it('fails an assertion chained onto a request', function () {
    $file = new File('packages/keystone/app-tests/Flows/ExampleTest.php', <<<'PHP'
        <?php

        it('signs in', function () {
            $this->post(route('login.submit', 'form'))->assertRedirect('/');
        });
        PHP);

    expect((new ResponsesThroughAssertionTraits)->check($file))->toBe([4]);
});

it('passes assertions called on the test case and on facades', function () {
    $file = new File('packages/keystone/app-tests/Flows/ExampleTest.php', <<<'PHP'
        <?php

        it('signs in', function () {
            $response = $this->post(route('login.submit', 'form'));

            $this->assertSignedIn($response, '/');
            Event::assertDispatched(Login::class);
        });
        PHP);

    expect((new ResponsesThroughAssertionTraits)->check($file))->toBe([]);
});

it('passes assertions called on anything but a response', function () {
    $file = new File('packages/keystone/app-tests/Flows/ExampleTest.php', <<<'PHP'
        <?php

        it('signs in', function () {
            $bus = Bus::fake();
            $mailable = new SignInAlert;

            $bus->assertNotDispatched(SendSignInAlert::class);
            $mailable->assertHasSubject('New sign-in');
        });
        PHP);

    expect((new ResponsesThroughAssertionTraits)->check($file))->toBe([]);
});

it('reads only the AppTests themselves', function (string $path) {
    $file = new File($path, "<?php\n\n\$response->assertRedirect('/');\n");

    expect((new ResponsesThroughAssertionTraits)->check($file))->toBe([]);
})->with([
    'packages/keystone/app-tests/Assertions/SignInAssertions.php',
    'packages/keystone/tests/Feature/ExampleTest.php',
]);
