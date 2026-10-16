<?php

use ClaudioDekker\Keystone\Lint\File;
use ClaudioDekker\Keystone\Lint\NoContainerLookups;

it('fails app() and resolve() inside a method', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        class Example
        {
            public function run(): void
            {
                app(Mailer::class)->send();
                resolve(Mailer::class)->send();
            }
        }
        PHP);

    expect((new NoContainerLookups)->check($file))->toBe([7, 8]);
});

it('fails a lookup in a closure inside a method', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        class Example
        {
            public function run(): callable
            {
                return fn () => app(Mailer::class);
            }
        }
        PHP);

    expect((new NoContainerLookups)->check($file))->toBe([7]);
});

it('passes a service injected through the constructor', function () {
    $file = new File('packages/keystone/src/Example.php', <<<'PHP'
        <?php

        class Example
        {
            public function __construct(protected Mailer $mailer)
            {
                //
            }

            public function run(): void
            {
                $this->mailer->send();
            }
        }
        PHP);

    expect((new NoContainerLookups)->check($file))->toBe([]);
});

it('leaves a lookup outside a method alone', function () {
    $file = new File('packages/keystone/src/helpers.php', "<?php\n\nfunction mailer() {\n    return app(Mailer::class);\n}\n");

    expect((new NoContainerLookups)->check($file))->toBe([]);
});

it('reads only src', function () {
    $file = new File('packages/keystone/tests/Fixtures/Example.php', "<?php\n\nclass Example {\n    public function run() {\n        app();\n    }\n}\n");

    expect((new NoContainerLookups)->check($file))->toBe([]);
});
