<?php

use ClaudioDekker\Keystone\Lint\File;
use ClaudioDekker\Keystone\Lint\NoEnvOutsideConfig;

it('fails env() outside a config file', function (string $path) {
    $file = new File($path, "<?php\n\n\$key = env('APP_KEY');\n\\env('APP_KEY');\n");

    expect((new NoEnvOutsideConfig)->check($file))->toBe([3, 4]);
})->with([
    'packages/keystone/src/Example.php',
    'packages/keystone/tests/Feature/ExampleTest.php',
    'tests/TestCase.php',
]);

it('passes env() in a config file of a package', function () {
    $file = new File('packages/keystone/config/keystone.php', "<?php\n\nreturn [\n    'key' => env('KEYSTONE_KEY'),\n];\n");

    expect((new NoEnvOutsideConfig)->check($file))->toBe([]);
});

it('passes the other ways to read the environment', function () {
    $file = new File('packages/keystone/src/Example.php', "<?php\n\n\$key = getenv('APP_KEY');\n\$name = Env::get('APP_NAME');\n\$env = \$app->environment();\n");

    expect((new NoEnvOutsideConfig)->check($file))->toBe([]);
});
