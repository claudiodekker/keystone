<?php

use ClaudioDekker\Keystone\Lint\Checks\NoPackageDependencies;
use ClaudioDekker\Keystone\Lint\File;

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

it('reads the namespaces of the other packages from their manifests', function () {
    $file = new File('packages/keystone/src/Example.php', "<?php\n\nuse ClaudioDekker\\Keystone\\Password\\PasswordType;\n");

    expect((new NoPackageDependencies)->check($file))->toBe([3]);
});
