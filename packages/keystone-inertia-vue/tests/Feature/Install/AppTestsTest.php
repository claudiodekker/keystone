<?php

use ClaudioDekker\Keystone\InertiaVue\Tests\InstallsIntoFreshApp;

uses(InstallsIntoFreshApp::class);

describe('phpunit.xml', function () {
    it('gets a Keystone testsuite reading every installed Keystone package\'s AppTests from vendor', function () {
        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('phpunit.xml'))->toContain(implode("\n", [
            '        <testsuite name="Feature">',
            '            <directory>tests/Feature</directory>',
            '        </testsuite>',
            '        <testsuite name="Keystone">',
            '            <directory>vendor/claudiodekker/keystone*/app-tests</directory>',
            '        </testsuite>',
            '    </testsuites>',
        ]));
    });

    it('prints the testsuite when the file has no testsuites element, and fails', function () {
        $this->fresh->put('phpunit.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<phpunit>\n</phpunit>\n");

        $this->artisan('keystone:install')
            ->expectsOutputToContain('<directory>vendor/claudiodekker/keystone*/app-tests</directory>')
            ->assertFailed();

        expect($this->fresh->contents('phpunit.xml'))->not->toContain('Keystone');
    });
});

describe('tests/Pest.php', function () {
    it("binds the app's Keystone test case to the AppTests in vendor", function () {
        $this->artisan('keystone:install')->assertSuccessful();

        expect($this->fresh->contents('tests/Pest.php'))->toContain(implode("\n", [
            "    ->in('Feature');",
            '',
            "pest()->extend(Tests\\Keystone\\TestCase::class)->in('../vendor/claudiodekker/keystone*/app-tests');",
            '',
        ]));
    });

    it('prints the binding when the file binds no test case, and fails', function () {
        $this->fresh->put('tests/Pest.php', "<?php\n");

        $this->artisan('keystone:install')
            ->expectsOutputToContain("pest()->extend(Tests\\Keystone\\TestCase::class)->in('../vendor/claudiodekker/keystone*/app-tests');")
            ->assertFailed();

        expect($this->fresh->contents('tests/Pest.php'))->toBe("<?php\n");
    });
});

describe('composer.json', function () {
    it("maps every installed Keystone package's AppTests in autoload-dev, never in autoload", function () {
        $this->artisan('keystone:install')->assertSuccessful();

        $manifest = json_decode($this->fresh->contents('composer.json'), true);

        expect($manifest['autoload-dev']['psr-4'])->toBe([
            'Tests\\' => 'tests/',
            'ClaudioDekker\\Keystone\\AppTests\\' => 'vendor/claudiodekker/keystone/app-tests/',
            'ClaudioDekker\\Keystone\\InertiaVue\\AppTests\\' => 'vendor/claudiodekker/keystone-inertia-vue/app-tests/',
            'ClaudioDekker\\Keystone\\Password\\AppTests\\' => 'vendor/claudiodekker/keystone-password/app-tests/',
        ])->and($manifest['autoload']['psr-4'])->not->toHaveKey('ClaudioDekker\\Keystone\\AppTests\\');
    });

    it('prints the mapping when the app maps an AppTests namespace elsewhere, and fails', function () {
        $manifest = json_decode($this->fresh->contents('composer.json'), true);
        $manifest['autoload-dev']['psr-4']['ClaudioDekker\\Keystone\\AppTests\\'] = 'tests/keystone/';
        $this->fresh->put('composer.json', $before = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->artisan('keystone:install')
            ->expectsOutputToContain('"ClaudioDekker\\\\Keystone\\\\AppTests\\\\": "vendor/claudiodekker/keystone/app-tests/"')
            ->assertFailed();

        expect($this->fresh->contents('composer.json'))->toBe($before);
    });

    it('adds the mappings and leaves every other line as the skeleton has it', function () {
        $before = explode("\n", $this->fresh->contents('composer.json'));
        expect($before)->toContain('    "keywords": ["laravel", "framework"],');

        $this->artisan('keystone:install')->assertSuccessful();

        $after = explode("\n", $this->fresh->contents('composer.json'));

        expect(array_values(array_diff($before, $after)))->toBe([
            '            "Tests\\\\": "tests/"',
        ])->and(array_values(array_diff($after, $before)))->toBe([
            '            "Tests\\\\": "tests/",',
            '            "ClaudioDekker\\\\Keystone\\\\AppTests\\\\": "vendor/claudiodekker/keystone/app-tests/",',
            '            "ClaudioDekker\\\\Keystone\\\\InertiaVue\\\\AppTests\\\\": "vendor/claudiodekker/keystone-inertia-vue/app-tests/",',
            '            "ClaudioDekker\\\\Keystone\\\\Password\\\\AppTests\\\\": "vendor/claudiodekker/keystone-password/app-tests/"',
        ]);
    });

    it('prints the mapping when autoload-dev is laid out differently, and fails', function () {
        $this->fresh->put('composer.json', $before = json_encode(json_decode($this->fresh->contents('composer.json')), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $this->artisan('keystone:install')
            ->expectsOutputToContain('"ClaudioDekker\\\\Keystone\\\\AppTests\\\\": "vendor/claudiodekker/keystone/app-tests/"')
            ->assertFailed();

        expect($this->fresh->contents('composer.json'))->toBe($before);
    });
});
