<?php

use ClaudioDekker\Keystone\Lint\Checks\DurationsAsProducts;
use ClaudioDekker\Keystone\Lint\File;

it('fails a duration in seconds that is a whole number of minutes', function () {
    $file = new File('packages/keystone/config/keystone.php', <<<'PHP'
        <?php

        return [
            'lifetime_seconds' => 43200,
            'window_seconds' => 120,
        ];
        PHP);

    expect((new DurationsAsProducts)->check($file))->toBe([4, 5]);
});

it('passes a duration written as a product, a single unit and a key that is not in seconds', function () {
    $file = new File('packages/keystone/config/keystone.php', <<<'PHP'
        <?php

        return [
            'cooldown_seconds' => 60,
            'grace_seconds' => 45,
            'lifetime_seconds' => 12 * 60 * 60,
            'odd_seconds' => 90,
            'retry' => 3600,
        ];
        PHP);

    expect((new DurationsAsProducts)->check($file))->toBe([]);
});

it('reads only the config files of a package', function () {
    $file = new File('packages/keystone/src/Example.php', "<?php\n\nreturn ['lifetime_seconds' => 43200];\n");

    expect((new DurationsAsProducts)->check($file))->toBe([]);
});
