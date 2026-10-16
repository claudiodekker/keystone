<?php

use ClaudioDekker\Keystone\Lint\File;
use ClaudioDekker\Keystone\Lint\SortedConfigKeys;

it('fails a key that sorts before the key above it, at any depth', function () {
    $file = new File('packages/keystone/config/keystone.php', <<<'PHP'
        <?php

        return [
            'lifetime' => 1,
            'enabled' => true,
            'nested' => [
                'zeta' => 1,
                'alpha' => 2,
            ],
        ];
        PHP);

    expect((new SortedConfigKeys)->check($file))->toBe([5, 8]);
});

it('passes keys in alphabetical order, and lists that have no keys', function () {
    $file = new File('packages/keystone/config/keystone.php', <<<'PHP'
        <?php

        return [
            'enabled' => true,
            'guards' => ['web', 'api'],
            'lifetime' => 1,
            'nested' => [
                'alpha' => 2,
                'zeta' => 1,
            ],
        ];
        PHP);

    expect((new SortedConfigKeys)->check($file))->toBe([]);
});

it('reads only the config files of a package', function () {
    $file = new File('packages/keystone/src/Example.php', "<?php\n\nreturn ['b' => 1, 'a' => 2];\n");

    expect((new SortedConfigKeys)->check($file))->toBe([]);
});
