<?php

use ClaudioDekker\Keystone\Lint\Checks\NoBlankLineOpeningBlocks;
use ClaudioDekker\Keystone\Lint\File;

it('fails a block that opens with a blank line', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        class Example
        {

            public function run(): int
            {

                return 1;
            }
        }
        PHP);

    expect((new NoBlankLineOpeningBlocks)->check($file))->toBe([5, 8]);
});

it('fails a block that opens with a blank line before a comment', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        if ($ready) {

            // Go.
            go();
        }
        PHP);

    expect((new NoBlankLineOpeningBlocks)->check($file))->toBe([4]);
});

it('passes a block that opens with its first statement, and braces that open no block', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        class Example
        {
            public function run(string $name): string
            {
                $greeting = "Hello {$name}";

                return $greeting;
            }
        }
        PHP);

    expect((new NoBlankLineOpeningBlocks)->check($file))->toBe([]);
});
