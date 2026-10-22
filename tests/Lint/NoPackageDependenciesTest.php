<?php

use ClaudioDekker\Keystone\Lint\Checks\NoPackageDependencies;
use ClaudioDekker\LaravelGuardrails\Kind;
use ClaudioDekker\LaravelGuardrails\Testing\TestFile;

it('fails core when it uses a class from a package its manifest maps', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone/src/Example.php', <<<'PHP'
        <?php

        namespace ClaudioDekker\Keystone;

        use ClaudioDekker\Keystone\Password\Rules\PasswordRule;

        class Example
        {
            //
        }
        PHP)->in(dirname(__DIR__, 2));

    expect($file->lines(new NoPackageDependencies))->toBe([5]);
});

it('passes core when it only uses core', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone/src/Example.php', <<<'PHP'
        <?php

        namespace ClaudioDekker\Keystone;

        use ClaudioDekker\Keystone\Actions\SignIn;
        use ClaudioDekker\Keystone\PasswordReset\Notification;

        class Example
        {
            //
        }
        PHP)->in(dirname(__DIR__, 2));

    expect($file->lines(new NoPackageDependencies))->toBe([]);
});

it('lets another package use itself', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/password/src/Example.php', <<<'PHP'
        <?php

        namespace ClaudioDekker\Keystone\Password;

        use ClaudioDekker\Keystone\Password\Rules\PasswordRule;
        PHP)->in(dirname(__DIR__, 2));

    expect($file->lines(new NoPackageDependencies))->toBe([]);
});
