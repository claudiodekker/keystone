<?php

use ClaudioDekker\Keystone\Lint\Checks\OneClassPerFile;
use ClaudioDekker\Keystone\Lint\File;

it('fails every declaration after the first', function () {
    $file = new File('lint/Example.php', <<<'PHP'
        <?php

        namespace App;

        class First
        {
            //
        }

        class Second
        {
            //
        }

        interface Third
        {
            //
        }

        trait Fourth
        {
            //
        }

        enum Fifth
        {
            //
        }
        PHP);

    expect((new OneClassPerFile)->check($file))->toBe([10, 15, 20, 25]);
});

it('passes a file with one class', function () {
    $file = new File('lint/Example.php', <<<'PHP'
        <?php

        namespace App;

        class Example
        {
            public function run(): object
            {
                return new class {};
            }
        }
        PHP);

    expect((new OneClassPerFile)->check($file))->toBe([]);
});

it('passes a Pest test file with closures and no class', function () {
    $file = new File('tests/ExampleTest.php', <<<'PHP'
        <?php

        it('runs', function () {
            expect(true)->toBeTrue();
        });
        PHP);

    expect((new OneClassPerFile)->check($file))->toBe([]);
});

it('reads every php file', function () {
    $file = new File('packages/keystone/src/Example.php', "<?php\n\nclass A {}\n\nclass B {}\n");

    expect((new OneClassPerFile)->check($file))->toBe([5]);
});
