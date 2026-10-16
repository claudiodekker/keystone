<?php

use ClaudioDekker\Keystone\Lint\File;
use ClaudioDekker\Keystone\Lint\NoDocblocksInTests;

it('fails a docblock above a test', function () {
    $file = new File('packages/keystone/tests/Feature/ExampleTest.php', <<<'PHP'
        <?php

        /**
         * Sign the user in.
         */
        it('signs the user in', function () {
            //
        });
        PHP);

    expect((new NoDocblocksInTests)->check($file))->toBe([3]);
});

it('fails a docblock inside a test', function () {
    $file = new File('tests/ExampleTest.php', <<<'PHP'
        <?php

        it('signs the user in', function () {
            /** @var \App\Models\User $user */
            $user = user();
        });
        PHP);

    expect((new NoDocblocksInTests)->check($file))->toBe([4]);
});

it('passes a test with plain comments and docblock text in a string', function () {
    $file = new File('tests/ExampleTest.php', <<<'PHP'
        <?php

        // Signs the user in.
        it('signs the user in', function () {
            /* The user */
            $user = "/** not a docblock */";
        });
        PHP);

    expect((new NoDocblocksInTests)->check($file))->toBe([]);
});

it('reads only test cases', function () {
    $file = new File('tests/TestCase.php', "<?php\n\n/**\n * Run a test.\n */\nclass TestCase {}\n");

    expect((new NoDocblocksInTests)->check($file))->toBe([]);
});
