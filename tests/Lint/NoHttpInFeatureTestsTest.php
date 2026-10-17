<?php

use ClaudioDekker\Keystone\Lint\Checks\NoHttpInFeatureTests;
use ClaudioDekker\Keystone\Lint\File;

it('fails a request through the test case in a Feature test', function () {
    $file = new File('packages/keystone/tests/Feature/ExampleTest.php', <<<'PHP'
        <?php

        it('signs in', function () {
            $this->post('/login/form');
            $this->getJson('/login');
            $this->call('DELETE', '/logout');
        });
        PHP);

    expect((new NoHttpInFeatureTests)->check($file))->toBe([4, 5, 6]);
});

it("fails a request through Pest's Laravel functions in a Feature test", function () {
    $file = new File('packages/keystone/tests/Feature/ExampleTest.php', <<<'PHP'
        <?php

        use function Pest\Laravel\post;

        it('signs in', function () {
            post('/login/form');
        });
        PHP);

    expect((new NoHttpInFeatureTests)->check($file))->toBe([6]);
});

it('passes calls that make no request', function () {
    $file = new File('packages/keystone/tests/Feature/ExampleTest.php', <<<'PHP'
        <?php

        it('calls the type', function () {
            $this->app->make(SignIn::class)->attempt($type, []);
            $type->get();
            get_class($type);
        });
        PHP);

    expect((new NoHttpInFeatureTests)->check($file))->toBe([]);
});

it('passes a Feature test the allowlist names with its reason', function () {
    $file = new File('packages/keystone/tests/Feature/ExampleTest.php', "<?php\n\n\$this->post('/login/form');\n");

    $check = new NoHttpInFeatureTests([
        'packages/keystone/tests/Feature/ExampleTest.php' => 'It installs a route no app has.',
    ]);

    expect($check->check($file))->toBe([]);
});

it('reads only the Feature tests of packages', function (string $path) {
    $file = new File($path, "<?php\n\n\$this->post('/login/form');\n");

    expect((new NoHttpInFeatureTests)->check($file))->toBe([]);
})->with([
    'packages/keystone/app-tests/Flows/ExampleTest.php',
    'packages/keystone/tests/Unit/ExampleTest.php',
]);
