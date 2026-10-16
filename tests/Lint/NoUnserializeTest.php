<?php

use ClaudioDekker\Keystone\Lint\Checks\NoUnserialize;
use ClaudioDekker\Keystone\Lint\File;

it('fails unserialize in src', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        unserialize($payload);
        \unserialize($payload, ['allowed_classes' => false]);
        PHP);

    expect((new NoUnserialize)->check($file))->toBe([3, 4]);
});

it('passes json and a method that happens to share the name', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $serializer->unserialize($payload);
        PHP);

    expect((new NoUnserialize)->check($file))->toBe([]);
});

it('reads only src', function () {
    $file = new File('packages/keystone/tests/Feature/ExampleTest.php', "<?php\n\nunserialize(\$payload);\n");

    expect((new NoUnserialize)->check($file))->toBe([]);
});
