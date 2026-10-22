<?php

use ClaudioDekker\Keystone\Lint\Checks\NoHttpInFeatureTests;
use ClaudioDekker\LaravelGuardrails\Kind;
use ClaudioDekker\LaravelGuardrails\Testing\TestFile;

it('fails a request through the test case in a Feature test', function () {
    $file = TestFile::of(Kind::TESTS, 'packages/keystone/tests/Feature/ExampleTest.php', <<<'PHP'
        <?php

        it('signs in', function () {
            $this->post('/login/form');
            $this->getJson('/login');
            $this->call('DELETE', '/logout');
        });
        PHP);

    expect($file->lines(new NoHttpInFeatureTests))->toBe([4, 5, 6]);
});

it("fails a request through Pest's Laravel functions in a Feature test", function () {
    $file = TestFile::of(Kind::TESTS, 'packages/keystone/tests/Feature/ExampleTest.php', <<<'PHP'
        <?php

        use function Pest\Laravel\post;

        it('signs in', function () {
            post('/login/form');
        });
        PHP);

    expect($file->lines(new NoHttpInFeatureTests))->toBe([6]);
});

it('passes calls that make no request', function () {
    $file = TestFile::of(Kind::TESTS, 'packages/keystone/tests/Feature/ExampleTest.php', <<<'PHP'
        <?php

        it('calls the type', function () {
            $this->app->make(SignIn::class)->attempt($type, []);
            $type->get();
            get_class($type);
        });
        PHP);

    expect($file->lines(new NoHttpInFeatureTests))->toBe([]);
});

it('reads only the Feature tests of packages', function (string $path) {
    $file = TestFile::of(Kind::TESTS, $path, "<?php\n\n\$this->post('/login/form');\n");

    expect($file->lines(new NoHttpInFeatureTests))->toBe([]);
})->with([
    'packages/keystone/app-tests/Flows/ExampleTest.php',
    'packages/keystone/tests/Unit/ExampleTest.php',
]);
