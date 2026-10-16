<?php

use ClaudioDekker\Keystone\Lint\Checks\NoEmDashes;
use ClaudioDekker\Keystone\Lint\File;

it('fails an em dash in any kind of file', function (string $path) {
    $file = new File($path, "Fast, \u{2014} and loose.\n\nNo dash here.\nOne \u{2014} two \u{2014} three.\n");

    expect((new NoEmDashes)->check($file))->toBe([1, 4]);
})->with([
    'docs/installation.md',
    'packages/keystone/src/Example.php',
    'composer.json',
    '.github/workflows/check.yml',
]);

it('passes the dashes that are not em dashes', function () {
    $file = new File('docs/installation.md', "A hyphen - and an en dash \u{2013} and two hyphens -- are fine.\n");

    expect((new NoEmDashes)->check($file))->toBe([]);
});
