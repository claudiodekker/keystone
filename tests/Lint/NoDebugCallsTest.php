<?php

use ClaudioDekker\Keystone\Lint\Checks\NoDebugCalls;
use ClaudioDekker\Keystone\Lint\File;

it('fails a call to a debug function', function (string $function) {
    $file = new File('packages/keystone/src/Example.php', "<?php\n\n{$function}(\$user);\n");

    expect((new NoDebugCalls)->check($file))->toBe([3]);
})->with(['dd', 'dump', 'var_dump', 'ray']);

it('fails a debug call written with a leading backslash', function () {
    $file = new File('packages/keystone/src/Example.php', "<?php\n\nnamespace App;\n\n\\dd(\$user);\n");

    expect((new NoDebugCalls)->check($file))->toBe([5]);
});

it('fails a debug call on a collection', function (string $method) {
    $file = new File('packages/keystone/src/Example.php', "<?php\n\n\$users->{$method}();\n");

    expect((new NoDebugCalls)->check($file))->toBe([3]);
})->with(['dd', 'dump', 'ray']);

it('passes a call that only looks like a debug call', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        dumpster($user);
        Log::debug('Signed in.');
        $users->each->dumpster();
        PHP);

    expect((new NoDebugCalls)->check($file))->toBe([]);
});

it('reads the tests too', function () {
    $file = new File('packages/keystone/tests/Feature/ExampleTest.php', "<?php\n\ndd(\$user);\n");

    expect((new NoDebugCalls)->check($file))->toBe([3]);
});
