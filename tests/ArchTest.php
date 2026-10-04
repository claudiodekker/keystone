<?php

const METHOD_NAMESPACES = [
    'ClaudioDekker\Keystone\Password',
    'ClaudioDekker\Keystone\WebAuthn',
    'ClaudioDekker\Keystone\Totp',
    'ClaudioDekker\Keystone\MagicLink',
    'ClaudioDekker\Keystone\OAuth',
];

const ADAPTER_NAMESPACES = [
    'ClaudioDekker\Keystone\InertiaVue',
    'ClaudioDekker\Keystone\Blade',
];

const PACKAGE_NAMESPACES = [...METHOD_NAMESPACES, ...ADAPTER_NAMESPACES];

const TEST_NAMESPACES = [
    'ClaudioDekker\Keystone\AppTests',
    'ClaudioDekker\Keystone\Tests',
    'ClaudioDekker\Keystone\Password\AppTests',
    'ClaudioDekker\Keystone\Password\Tests',
    'ClaudioDekker\Keystone\WebAuthn\Tests',
    'ClaudioDekker\Keystone\Totp\AppTests',
    'ClaudioDekker\Keystone\Totp\Tests',
    'ClaudioDekker\Keystone\MagicLink\Tests',
    'ClaudioDekker\Keystone\OAuth\Tests',
    'ClaudioDekker\Keystone\InertiaVue\AppTests',
    'ClaudioDekker\Keystone\InertiaVue\Tests',
    'ClaudioDekker\Keystone\Blade\Tests',
];

arch('debugging functions are never left in')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'error_log'])
    ->not->toBeUsed();

arch('src never depends on tests')
    ->expect([...TEST_NAMESPACES, 'Tests'])
    ->toOnlyBeUsedIn([...TEST_NAMESPACES, 'Tests']);

arch('core never depends on a method or adapter package')
    ->expect(PACKAGE_NAMESPACES)
    ->toOnlyBeUsedIn([...PACKAGE_NAMESPACES, 'Tests']);

foreach (METHOD_NAMESPACES as $method) {
    arch("{$method} never depends on an adapter or another method package")
        ->expect($method)
        ->not->toUse([...ADAPTER_NAMESPACES, ...array_diff(METHOD_NAMESPACES, [$method])]);
}

foreach (ADAPTER_NAMESPACES as $adapter) {
    arch("{$adapter} never depends on a method package, so it works with none installed")
        ->expect($adapter)
        ->not->toUse(METHOD_NAMESPACES)
        ->ignoring(TEST_NAMESPACES);
}

arch('the adapter stubs never depend on a method package')
    ->expect('App\Http\Controllers\Auth')
    ->not->toUse(METHOD_NAMESPACES);

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
    $responseAssertion = '/->assert(Ok|Status|Successful|Redirect|Location|Json|Session|Header|Cookie|See|View|Inertia|Created|NoContent|Unauthorized|Forbidden|NotFound|TooManyRequests|Unprocessable|Gone|Valid|Invalid)\w*\(/';
    $offenders = filesContaining($appTests, $responseAssertion);

    expect($offenders)->toBe([]);
});

test('src uses no weak hash, no predictable randomness, no unserialize and no eval', function () {
    $sources = preg_grep('/\.php$/', packageFiles('src'));
    $offenders = filesContaining($sources, '/(?<![\w$>:])(md5|sha1|rand|mt_rand|uniqid|unserialize|eval)\s*\(/');

    expect($sources)->not->toBe([])
        ->and($offenders)->toBe([]);
});

test('app tests declare no global functions, so they never clash with the app\'s own', function () {
    $files = packageFiles('app-tests');
    $offenders = filesContaining($files, '/^\s*function\s+\w+\s*\(/m');

    expect($files)->not->toBe([])
        ->and($offenders)->toBe([]);
});

test('package tests live in Unit or Feature', function () {
    $files = packageFiles('tests');
    $tests = preg_grep('/Test\.php$/', $files);
    $misplaced = preg_grep('#/packages/[^/]+/tests/(Unit|Feature)/#', $tests, PREG_GREP_INVERT);
    $offenders = array_values($misplaced);

    expect($offenders)->toBe([]);
});

test('swappable actions live in src/Actions, and jobs in src/Jobs', function () {
    $files = packageFiles('src');
    $sources = preg_grep('#/src/(Actions|Jobs)/#', $files, PREG_GREP_INVERT);
    $publicClasses = filesContaining($sources, '/@api/');
    $offenders = filesContaining($publicClasses, '/public function handle\(/');

    expect($offenders)->toBe([]);
});

test('every Keystone mail and notification is encrypted on the queue', function () {
    $files = packageFiles('src');
    $messages = filesContaining($files, '/\bextends (Notification|Mailable)\b/');
    $offenders = array_values(array_diff($messages, filesContaining($messages, '/\bimplements\b[^{]*\bShouldBeEncrypted\b/')));

    expect($messages)->not->toBe([])
        ->and($offenders)->toBe([]);
});
