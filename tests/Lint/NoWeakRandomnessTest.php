<?php

use ClaudioDekker\Keystone\Lint\File;
use ClaudioDekker\Keystone\Lint\NoWeakRandomness;

it('fails rand, mt_rand and uniqid in src', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        rand(1, 10);
        mt_rand(1, 10);
        \uniqid();
        PHP);

    expect((new NoWeakRandomness)->check($file))->toBe([3, 4, 5]);
});

it('passes the secure sources of randomness', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        random_int(1, 10);
        random_bytes(16);
        Str::random(40);
        $dice->rand();
        PHP);

    expect((new NoWeakRandomness)->check($file))->toBe([]);
});

it('reads only src', function () {
    $file = new File('packages/keystone/tests/Feature/ExampleTest.php', "<?php\n\nrand(1, 10);\n");

    expect((new NoWeakRandomness)->check($file))->toBe([]);
});
