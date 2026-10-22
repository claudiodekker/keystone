<?php

use ClaudioDekker\Keystone\Lint\Checks\NoFactoriesInAppTests;
use ClaudioDekker\LaravelGuardrails\Kind;
use ClaudioDekker\LaravelGuardrails\Testing\TestFile;

it('fails a model factory called in an AppTest', function () {
    $file = TestFile::of(Kind::TESTS, 'packages/keystone/app-tests/Flows/ExampleTest.php', <<<'PHP'
        <?php

        it('signs in', function () {
            $user = User::factory()->create();
            UserEmail::factory()->for($user, 'user')->verified()->create();
        });
        PHP);

    expect($file->lines(new NoFactoriesInAppTests))->toBe([4, 5]);
});

it('passes the harness helpers that call the factories', function () {
    $file = TestFile::of(Kind::TESTS, 'packages/keystone/app-tests/Concerns/Example.php', <<<'PHP'
        <?php

        trait Example
        {
            public function createUser(): KeystoneUser
            {
                return User::factory()->create();
            }
        }
        PHP);

    expect($file->lines(new NoFactoriesInAppTests))->toBe([]);
});

it('reads only the AppTests', function () {
    $file = TestFile::of(Kind::TESTS, 'packages/keystone/tests/Feature/ExampleTest.php', "<?php\n\nUserEmail::factory()->create();\n");

    expect($file->lines(new NoFactoriesInAppTests))->toBe([]);
});
