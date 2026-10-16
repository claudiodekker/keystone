<?php

use ClaudioDekker\Keystone\Lint\File;
use ClaudioDekker\Keystone\Lint\NoPackageDependencies;

it('fails core when it uses a class from another package', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        namespace ClaudioDekker\Keystone;

        use ClaudioDekker\Keystone\Password\Rules\PasswordRule;

        class Example
        {
            //
        }
        PHP);

    expect((new NoPackageDependencies(['ClaudioDekker\\Keystone\\Password\\']))->check($file))->toBe([5]);
});

it('passes core when it only uses core', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        namespace ClaudioDekker\Keystone;

        use ClaudioDekker\Keystone\Actions\SignIn;
        use ClaudioDekker\Keystone\PasswordReset\Notification;

        class Example
        {
            //
        }
        PHP);

    expect((new NoPackageDependencies(['ClaudioDekker\\Keystone\\Password\\']))->check($file))->toBe([]);
});

it('lets another package use itself', function () {
    $file = new File('packages/password/src/Example.php', <<<'PHP'
        <?php

        namespace ClaudioDekker\Keystone\Password;

        use ClaudioDekker\Keystone\Password\Rules\PasswordRule;
        PHP);

    expect((new NoPackageDependencies(['ClaudioDekker\\Keystone\\Password\\']))->check($file))->toBe([]);
});

it('finds no other package while core is the only one', function () {
    $file = new File('packages/keystone/src/Example.php', "<?php\n\nuse ClaudioDekker\\Keystone\\Password\\Rules\\PasswordRule;\n");

    expect((new NoPackageDependencies)->check($file))->toBe([]);
});
