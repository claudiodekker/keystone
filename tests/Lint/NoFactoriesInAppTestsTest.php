<?php

use ClaudioDekker\Keystone\Lint\Checks\NoFactoriesInAppTests;
use ClaudioDekker\Keystone\Lint\File;

it('fails a model factory called in an AppTest', function () {
    $file = new File('packages/keystone/app-tests/Flows/ExampleTest.php', <<<'PHP'
        <?php

        it('signs in', function () {
            $user = User::factory()->create();
            UserEmail::factory()->for($user, 'user')->verified()->create();
        });
        PHP);

    expect((new NoFactoriesInAppTests)->check($file))->toBe([4, 5]);
});

it('passes the harness helpers that call the factories', function () {
    $file = new File('packages/keystone/app-tests/Concerns/Example.php', <<<'PHP'
        <?php

        trait Example
        {
            public function createUser(): KeystoneUser
            {
                return User::factory()->create();
            }
        }
        PHP);

    expect((new NoFactoriesInAppTests)->check($file))->toBe([]);
});

it('reads only the AppTests', function () {
    $file = new File('packages/keystone/tests/Feature/ExampleTest.php', "<?php\n\nUserEmail::factory()->create();\n");

    expect((new NoFactoriesInAppTests)->check($file))->toBe([]);
});
