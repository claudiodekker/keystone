<?php

use ClaudioDekker\Keystone\Lint\Checks\DocumentedDeclarations;
use ClaudioDekker\Keystone\Lint\File;

it('fails a class, property, method and function without a docblock', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        class Example
        {
            protected string $name;

            public function run(): void
            {
                //
            }
        }

        function helper(): void
        {
            //
        }
        PHP);

    expect((new DocumentedDeclarations)->check($file))->toBe([3, 5, 7, 13]);
});

it('fails an interface, trait and enum without a docblock', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        interface Reader
        {
            //
        }

        trait Writes
        {
            //
        }

        enum Mode
        {
            //
        }
        PHP);

    expect((new DocumentedDeclarations)->check($file))->toBe([3, 8, 13]);
});

it('passes declarations that have a docblock', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        /**
         * Do the example.
         */
        class Example
        {
            /**
             * The name.
             */
            protected string $name;

            /**
             * Run the example.
             */
            public function run(): void
            {
                //
            }
        }

        /**
         * Help.
         */
        function helper(): void
        {
            //
        }
        PHP);

    expect((new DocumentedDeclarations)->check($file))->toBe([]);
});

it('counts a constructor docblock as the docblock of its promoted properties', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        /**
         * Do the example.
         */
        class Example
        {
            /**
             * Create the example.
             */
            public function __construct(protected string $name)
            {
                //
            }
        }
        PHP);

    expect((new DocumentedDeclarations)->check($file))->toBe([]);
});

it('does not ask a closure or an anonymous class for a docblock', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        $double = fn (int $number) => $number * 2;
        $example = new class {};
        PHP);

    expect((new DocumentedDeclarations)->check($file))->toBe([]);
});

it('reads src, test support code and the lint', function (string $path) {
    $file = new File($path, "<?php\n\nclass Example {}\n");

    expect((new DocumentedDeclarations)->check($file))->toBe([3]);
})->with([
    'packages/keystone/src/Example.php',
    'packages/keystone/app-tests/Concerns/Example.php',
    'packages/keystone/tests/Support/Example.php',
    'tests/Example.php',
    'lint/Example.php',
]);

it('leaves the test cases without a docblock', function () {
    $file = new File('packages/keystone/tests/Feature/ExampleTest.php', "<?php\n\nfunction helper() {}\n");

    expect((new DocumentedDeclarations)->check($file))->toBe([]);
});
