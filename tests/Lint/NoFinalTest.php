<?php

use ClaudioDekker\Keystone\Lint\Checks\NoFinal;
use ClaudioDekker\Keystone\Lint\File;

it('fails a final class in src', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        final class Example
        {
            //
        }
        PHP);

    expect((new NoFinal)->check($file))->toBe([3]);
});

it('fails a final method, constant and property in src', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        class Example
        {
            final public const NAME = 'example';

            final public string $name;

            final public function run(): void
            {
                //
            }
        }
        PHP);

    expect((new NoFinal)->check($file))->toBe([5, 7, 9]);
});

it('passes a class that can be extended', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        abstract class Example
        {
            public const NAME = 'final';

            protected function run(): string
            {
                return 'final';
            }
        }
        PHP);

    expect((new NoFinal)->check($file))->toBe([]);
});

it('reads the AppTests and test support of packages, which apps extend too', function (string $path) {
    $file = new File($path, "<?php\n\nfinal class Example {}\n");

    expect((new NoFinal)->check($file))->toBe([3]);
})->with([
    'packages/keystone/app-tests/Concerns/Example.php',
    'packages/keystone/tests/Fixtures/Example.php',
]);

it('reads only the packages', function () {
    $file = new File('tests/Example.php', "<?php\n\nfinal class Example {}\n");

    expect((new NoFinal)->check($file))->toBe([]);
});
