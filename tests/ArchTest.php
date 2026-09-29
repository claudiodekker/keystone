<?php

const PACKAGE_NAMESPACES = [
    'ClaudioDekker\Keystone\Password',
    'ClaudioDekker\Keystone\WebAuthn',
    'ClaudioDekker\Keystone\Totp',
    'ClaudioDekker\Keystone\MagicLink',
    'ClaudioDekker\Keystone\OAuth',
    'ClaudioDekker\Keystone\InertiaVue',
    'ClaudioDekker\Keystone\Blade',
];

const TEST_NAMESPACES = [
    'ClaudioDekker\Keystone\AppTests',
    'ClaudioDekker\Keystone\Tests',
    'ClaudioDekker\Keystone\Password\AppTests',
    'ClaudioDekker\Keystone\Password\Tests',
    'ClaudioDekker\Keystone\WebAuthn\Tests',
    'ClaudioDekker\Keystone\Totp\Tests',
    'ClaudioDekker\Keystone\MagicLink\Tests',
    'ClaudioDekker\Keystone\OAuth\Tests',
    'ClaudioDekker\Keystone\InertiaVue\AppTests',
    'ClaudioDekker\Keystone\InertiaVue\Tests',
    'ClaudioDekker\Keystone\Blade\Tests',
];

arch('debugging functions are never left in')
    ->expect(['dd', 'dump', 'ray'])
    ->not->toBeUsed();

arch('src never depends on tests')
    ->expect([...TEST_NAMESPACES, 'Tests'])
    ->toOnlyBeUsedIn([...TEST_NAMESPACES, 'Tests']);

arch('core never depends on a method or adapter package')
    ->expect(PACKAGE_NAMESPACES)
    ->toOnlyBeUsedIn([...PACKAGE_NAMESPACES, 'Tests']);

function packageFiles(string $folder): array
{
    $files = [];
    $directories = glob(dirname(__DIR__)."/packages/*/{$folder}", GLOB_ONLYDIR);

    foreach ($directories as $directory) {
        $children = new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);
        $tree = new RecursiveIteratorIterator($children);

        foreach ($tree as $file) {
            $files[] = $file->getPathname();
        }
    }

    return $files;
}

function filesContaining(array $files, string $pattern): array
{
    $matches = array_filter($files, function (string $file) use ($pattern) {
        $contents = file_get_contents($file);

        return preg_match($pattern, $contents) === 1;
    });

    return array_values($matches);
}

test('app tests check responses only through their overridable assertion traits', function () {
    $files = packageFiles('app-tests');
    $appTests = preg_grep('/Test\.php$/', $files);
    $responseAssertion = '/->assert(Ok|Status|Successful|Redirect|Location|Json|Session|Header|Cookie|See|View|Inertia|Created|NoContent|Unauthorized|Forbidden|NotFound|Valid|Invalid)\w*\(/';
    $offenders = filesContaining($appTests, $responseAssertion);

    expect($offenders)->toBe([]);
});

test('package tests live in Unit or Feature', function () {
    $files = packageFiles('tests');
    $tests = preg_grep('/Test\.php$/', $files);
    $misplaced = preg_grep('#/packages/[^/]+/tests/(Unit|Feature)/#', $tests, PREG_GREP_INVERT);
    $offenders = array_values($misplaced);

    expect($offenders)->toBe([]);
});

test('swappable actions live in src/Actions', function () {
    $files = packageFiles('src');
    $sources = preg_grep('#/src/Actions/#', $files, PREG_GREP_INVERT);
    $publicClasses = filesContaining($sources, '/@api/');
    $offenders = filesContaining($publicClasses, '/public function handle\(/');

    expect($offenders)->toBe([]);
});
