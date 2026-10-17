<?php

use ClaudioDekker\Keystone\Lint\Checks\NoInternalReferences;
use ClaudioDekker\Keystone\Lint\File;

it('fails a test tagged with a group', function () {
    $file = new File('packages/keystone/app-tests/Flows/ExampleTest.php', <<<'PHP'
        <?php

        it('signs in', function () {
            //
        })->group('SI-3');

        it('signs out', fn () => null)->with([1])->group('H2', 'SI-11');
        PHP);

    expect((new NoInternalReferences)->check($file))->toBe([3, 7]);
});

it('fails a decision record named in a package', function () {
    $file = new File('packages/keystone/src/Example.php', "<?php\n\n// Kept in place, as ADR 0003 decided.\n\$a = 1;\n");

    expect((new NoInternalReferences)->check($file))->toBe([3]);
});

it('passes route groups', function () {
    $file = new File('packages/keystone/tests/Fixtures/FixtureServiceProvider.php', <<<'PHP'
        <?php

        Route::middleware('web')->group(__DIR__.'/routes/keystone.php');
        PHP);

    expect((new NoInternalReferences)->check($file))->toBe([]);
});

it('reads only the packages', function () {
    $file = new File('docs/adr/0035-apptests-are-the-feature-suite.md', "See ADR 0005.\n");

    expect((new NoInternalReferences)->check($file))->toBe([]);
});
