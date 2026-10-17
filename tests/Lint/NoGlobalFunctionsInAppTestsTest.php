<?php

use ClaudioDekker\Keystone\Lint\Checks\NoGlobalFunctionsInAppTests;
use ClaudioDekker\Keystone\Lint\File;

it('fails a function declared in an AppTest', function () {
    $file = new File('packages/keystone/app-tests/Flows/ExampleTest.php', <<<'PHP'
        <?php

        function signIn(): void
        {
            //
        }

        it('signs in', fn () => signIn());
        PHP);

    expect((new NoGlobalFunctionsInAppTests)->check($file))->toBe([3]);
});

it('passes closures and the methods of harness classes', function () {
    $file = new File('packages/keystone/app-tests/Concerns/Example.php', <<<'PHP'
        <?php

        trait Example
        {
            public function signIn(): void
            {
                $close = fn () => null;
            }
        }
        PHP);

    expect((new NoGlobalFunctionsInAppTests)->check($file))->toBe([]);
});

it('reads only the AppTests', function () {
    $file = new File('packages/keystone/tests/Feature/ExampleTest.php', "<?php\n\nfunction helper(): void {}\n");

    expect((new NoGlobalFunctionsInAppTests)->check($file))->toBe([]);
});
