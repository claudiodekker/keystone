<?php

use ClaudioDekker\Keystone\Lint\File;
use ClaudioDekker\Keystone\Lint\NoTestDependencies;

it('fails a class in src that uses a class from tests', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        namespace ClaudioDekker\Keystone;

        use ClaudioDekker\Keystone\Tests\Fixtures\RecordsItsRun;

        class Example
        {
            //
        }
        PHP);

    expect((new NoTestDependencies)->check($file))->toBe([5]);
});

it('fails a reference to a package\'s own tests namespace', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        namespace ClaudioDekker\Keystone;

        class Example
        {
            public function name(): string
            {
                return \ClaudioDekker\Keystone\Password\Tests\Fixtures\Hasher::class;
            }
        }
        PHP);

    expect((new NoTestDependencies)->check($file))->toBe([9]);
});

it('passes a class in src that uses another class in src', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        namespace ClaudioDekker\Keystone;

        use ClaudioDekker\Keystone\Actions\SignIn;
        use ClaudioDekker\Keystone\Testimonials\Quote;

        class Example
        {
            //
        }
        PHP);

    expect((new NoTestDependencies)->check($file))->toBe([]);
});

it('reads only src', function () {
    $file = new File('packages/keystone/tests/Feature/Example.php', <<<'PHP'
        <?php

        use ClaudioDekker\Keystone\Tests\Fixtures\RecordsItsRun;
        PHP);

    expect((new NoTestDependencies)->check($file))->toBe([]);
});
