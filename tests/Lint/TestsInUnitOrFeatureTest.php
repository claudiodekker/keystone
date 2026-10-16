<?php

use ClaudioDekker\Keystone\Lint\Checks\TestsInUnitOrFeature;
use ClaudioDekker\Keystone\Lint\File;

it('fails a package test outside Unit and Feature', function (string $path) {
    $file = new File($path, "<?php\n\nit('does a thing', function () {});\n");

    expect((new TestsInUnitOrFeature)->check($file))->toBe([1]);
})->with([
    'packages/keystone/tests/ExampleTest.php',
    'packages/keystone/tests/Http/ExampleTest.php',
    'packages/keystone/tests/Http/Feature/ExampleTest.php',
]);

it('passes a package test in Unit or Feature', function (string $path) {
    $file = new File($path, "<?php\n\nit('does a thing', function () {});\n");

    expect((new TestsInUnitOrFeature)->check($file))->toBe([]);
})->with([
    'packages/keystone/tests/Unit/ExampleTest.php',
    'packages/keystone/tests/Unit/Actions/ExampleTest.php',
    'packages/keystone/tests/Feature/ExampleTest.php',
    'packages/keystone/tests/Feature/Http/ExampleTest.php',
]);

it('leaves the support code and the tests of other folders alone', function (string $path) {
    $file = new File($path, "<?php\n\nit('does a thing', function () {});\n");

    expect((new TestsInUnitOrFeature)->check($file))->toBe([]);
})->with([
    'packages/keystone/tests/Support/Example.php',
    'packages/keystone/app-tests/ExampleTest.php',
    'tests/ExampleTest.php',
]);
