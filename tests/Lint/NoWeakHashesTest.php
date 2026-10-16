<?php

use ClaudioDekker\Keystone\Lint\Checks\NoWeakHashes;
use ClaudioDekker\Keystone\Lint\File;

it('fails md5 and sha1 in src', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        md5($value);
        \sha1($value);
        PHP);

    expect((new NoWeakHashes)->check($file))->toBe([3, 4]);
});

it('passes the stronger hashes', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        hash('sha256', $value);
        hash_hmac('sha256', $value, $key);
        $digest->md5();
        PHP);

    expect((new NoWeakHashes)->check($file))->toBe([]);
});

it('reads only src', function () {
    $file = new File('packages/keystone/tests/Feature/ExampleTest.php', "<?php\n\nmd5(\$value);\n");

    expect((new NoWeakHashes)->check($file))->toBe([]);
});
