<?php

use ClaudioDekker\Keystone\Lint\Checks\NoContainerLookups;
use ClaudioDekker\LaravelGuardrails\Kind;
use ClaudioDekker\LaravelGuardrails\Testing\TestFile;

it('fails app() and resolve() inside a method', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone/src/Example.php', <<<'PHP'
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

    expect($file->lines(new NoContainerLookups))->toBe([7, 8]);
});

it('fails a lookup in a closure inside a method', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone/src/Example.php', <<<'PHP'
        <?php

        class Example
        {
            public function run(): callable
            {
                return fn () => app(Mailer::class);
            }
        }
        PHP);

    expect($file->lines(new NoContainerLookups))->toBe([7]);
});

it('passes a service injected through the constructor', function () {
    $file = TestFile::of(Kind::SOURCE, 'packages/keystone/src/Example.php', <<<'PHP'
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

    expect($file->lines(new NoContainerLookups))->toBe([]);
});

it('reads only the source code', function () {
    $file = TestFile::of(Kind::TESTS, 'packages/keystone/tests/Fixtures/Example.php', "<?php\n\nclass Example {\n    public function run() {\n        app();\n    }\n}\n");

    expect($file->lines(new NoContainerLookups))->toBe([]);
});
