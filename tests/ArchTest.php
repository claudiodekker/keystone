<?php

use ClaudioDekker\Keystone\Http\Middleware\RequireSudo;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\GatedProbeController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\OverridingGatedProbeController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\UnguardedGatedProbeController;
use Illuminate\Routing\Controllers\Middleware;

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

function coreControllerClasses(): array
{
    $files = glob(dirname(__DIR__).'/packages/keystone/src/Http/Controllers/*Controller.php');
    $names = array_map(fn (string $file) => basename($file, '.php'), $files);
    $names = array_values(array_diff($names, ['Controller']));

    return array_combine($names, array_map(fn (string $name) => 'ClaudioDekker\Keystone\Http\Controllers\\'.$name, $names));
}

function unpairedSudoActions(string $controller): array
{
    $listed = [];
    $enforced = [];

    foreach ($controller::middleware() as $middleware) {
        if ($middleware instanceof Middleware && $middleware->middleware === RequireSudo::class) {
            $listed = [...$listed, ...$middleware->only ?? []];
        }
    }

    foreach ((new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        $body = substr($source, strpos($source, '{') + 1);

        if (preg_match('/^\s*\(new SudoGate\(Keystone::guard\(\)\)\)->enforce\(\$request\);/', $body) === 1) {
            $enforced[] = $method->getName();
        }
    }

    return array_values(array_unique([...array_diff($listed, $enforced), ...array_diff($enforced, $listed)]));
}

test('app tests check responses only through their overridable assertion traits', function () {
    $files = packageFiles('app-tests');
    $appTests = preg_grep('/Test\.php$/', $files);
    $responseAssertion = '/->assert(Ok|Status|Successful|Redirect|Location|Json|Session(Has|Missing|Doesnt)|Header|Cookie|See|View|Inertia|Created|NoContent|Unauthorized|Forbidden|NotFound|TooManyRequests|Unprocessable|Gone|Valid|Invalid)\w*\(/';
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

test('only the account change unit writes credentials and recovery codes', function () {
    $files = packageFiles('src');
    $callers = preg_grep('#/src/(AccountChange|Credentials|RecoveryCodes)\.php$#', $files, PREG_GREP_INVERT);
    $credentialWriters = filesContaining($callers, '/(?:credentials|Credentials\([^()]*\)\))->(?:store|replaceSecret|stampLastUse)\(/i');
    $recoveryCodeWriters = filesContaining($callers, '/(?:recoveryCodes|codes|RecoveryCodes\([^()]*\)\))->(?:replace|spend)\(/i');

    expect($files)->not->toBe([])
        ->and($credentialWriters)->toBe([])
        ->and($recoveryCodeWriters)->toBe([]);
});

test('only the account change unit deletes credentials', function () {
    $files = packageFiles('src');
    $callers = preg_grep('#/src/(AccountChange|Credentials)\.php$#', $files, PREG_GREP_INVERT);
    $deleters = filesContaining($callers, '/(?:credentials|Credentials\([^()]*\)\))->delete\(|[\'"]user_credentials[\'"]\)[^;]*->delete\(/i');

    expect($files)->not->toBe([])
        ->and($deleters)->toBe([]);
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

test('every core action behind the sudo middleware enforces the gate on its first line, and every action enforcing it is behind the middleware', function (string $controller) {
    expect(unpairedSudoActions($controller))->toBe([]);
})->with(fn () => coreControllerClasses());

test('the sudo pairing check catches an action missing either half', function () {
    expect(unpairedSudoActions(GatedProbeController::class))->toBe([])
        ->and(unpairedSudoActions(UnguardedGatedProbeController::class))->toBe(['destroy'])
        ->and(unpairedSudoActions(OverridingGatedProbeController::class))->toBe(['destroy']);
});
