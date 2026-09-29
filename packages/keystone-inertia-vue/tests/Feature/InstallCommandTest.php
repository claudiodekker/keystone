<?php

use ClaudioDekker\Keystone\InertiaVue\Tests\StubsTestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

pest()->extend(TestCase::class);

function freshApp(array $composer = []): string
{
    $path = sys_get_temp_dir().'/keystone-install-'.bin2hex(random_bytes(6));
    $files = new Filesystem;
    $files->copyDirectory(dirname(__DIR__).'/Fixtures/app', $path);
    $files->copy("{$path}/.env.example", "{$path}/.env");

    if ($composer !== []) {
        $manifest = json_decode($files->get("{$path}/composer.json"), true);
        $files->put("{$path}/composer.json", json_encode(array_replace_recursive($manifest, $composer), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    register_shutdown_function(fn () => $files->deleteDirectory($path));

    app()->setBasePath($path);
    config(['auth.providers.users.model' => 'App\Models\User']);
    Process::fake();

    return $path;
}

it('refuses while laravel/fortify is installed, changing nothing', function () {
    $app = freshApp(['require' => ['laravel/fortify' => '^1.37']]);
    $before = file_get_contents("{$app}/routes/web.php");

    $this->artisan('keystone:install')
        ->expectsOutputToContain('Remove laravel/fortify')
        ->assertFailed()
        ->run();

    expect(file_get_contents("{$app}/routes/web.php"))->toBe($before)
        ->and("{$app}/routes/keystone.php")->not->toBeFile();
});

it('refuses without Pest, which Keystone\'s AppTests run on', function () {
    $app = freshApp();
    $manifest = json_decode(file_get_contents("{$app}/composer.json"), true);
    unset($manifest['require-dev']['pestphp/pest']);
    file_put_contents("{$app}/composer.json", json_encode($manifest));

    $this->artisan('keystone:install')
        ->expectsOutputToContain('Install Pest')
        ->assertFailed()
        ->run();

    expect("{$app}/routes/keystone.php")->not->toBeFile();
});

it('copies the stubs into the app', function () {
    $app = freshApp();

    $this->artisan('keystone:install')->assertSuccessful()->run();

    expect("{$app}/app/Http/Controllers/Auth/SignInController.php")->toBeFile()
        ->and("{$app}/app/Http/Controllers/Auth/SignOutController.php")->toBeFile()
        ->and("{$app}/routes/keystone.php")->toBeFile()
        ->and("{$app}/resources/js/pages/auth/Login.vue")->toBeFile()
        ->and("{$app}/resources/js/partials/shapes/Form.vue")->toBeFile()
        ->and("{$app}/resources/js/types/auth.ts")->toBeFile()
        ->and("{$app}/tests/Keystone/Assertions/SignInAssertions.php")->toBeFile()
        ->and(file_get_contents("{$app}/routes/keystone.php"))->toBe(file_get_contents(StubsTestCase::STUBS.'/routes/keystone.php'));
});

it('copies the partials of installed credential types only', function () {
    $app = freshApp();
    $partials = StubsTestCase::STUBS.'/resources/js/partials';
    file_put_contents("{$partials}/NotInstalled.vue", '<template />');

    try {
        $this->artisan('keystone:install')->assertSuccessful()->run();
    } finally {
        unlink("{$partials}/NotInstalled.vue");
    }

    expect("{$app}/resources/js/partials/Password.vue")->toBeFile()
        ->and("{$app}/resources/js/partials/NotInstalled.vue")->not->toBeFile();
});

it('never copies the Wayfinder output generated at build', function () {
    $app = freshApp();
    $generated = StubsTestCase::STUBS.'/resources/js/routes';
    $existed = is_dir($generated);
    @mkdir($generated);
    file_put_contents("{$generated}/probe.ts", 'export {};');

    try {
        $this->artisan('keystone:install')->assertSuccessful()->run();
    } finally {
        unlink("{$generated}/probe.ts");
        $existed || rmdir($generated);
    }

    expect("{$app}/resources/js/routes")->not->toBeDirectory();
});

it('skips and lists the files the app already has', function () {
    $app = freshApp();
    mkdir("{$app}/app/Http/Controllers/Auth", recursive: true);
    file_put_contents("{$app}/app/Http/Controllers/Auth/SignInController.php", '<?php // mine');

    $this->artisan('keystone:install')
        ->expectsOutputToContain('app/Http/Controllers/Auth/SignInController.php')
        ->assertSuccessful()
        ->run();

    expect(file_get_contents("{$app}/app/Http/Controllers/Auth/SignInController.php"))->toBe('<?php // mine');
});

it('overwrites the files the app already has when forced', function () {
    $app = freshApp();
    mkdir("{$app}/app/Http/Controllers/Auth", recursive: true);
    file_put_contents("{$app}/app/Http/Controllers/Auth/SignInController.php", '<?php // mine');

    $this->artisan('keystone:install', ['--force' => true])->assertSuccessful()->run();

    expect(file_get_contents("{$app}/app/Http/Controllers/Auth/SignInController.php"))
        ->toBe(file_get_contents(StubsTestCase::STUBS.'/app/Http/Controllers/Auth/SignInController.php'));
});

it('requires the route file from routes/web.php once', function () {
    $app = freshApp();

    $this->artisan('keystone:install')->assertSuccessful()->run();
    $this->artisan('keystone:install')->assertSuccessful()->run();

    expect(substr_count(file_get_contents("{$app}/routes/web.php"), "require __DIR__.'/keystone.php';"))->toBe(1);
});

it('makes the user model a Keystone user once', function () {
    $app = freshApp();

    $this->artisan('keystone:install')->assertSuccessful()->run();
    $this->artisan('keystone:install')->assertSuccessful()->run();

    $model = file_get_contents("{$app}/app/Models/User.php");
    expect($model)->toContain("use ClaudioDekker\\Keystone\\HasKeystone;\nuse ClaudioDekker\\Keystone\\KeystoneUser;\n")
        ->toContain('class User extends Authenticatable implements KeystoneUser')
        ->toContain('use HasFactory, Notifiable, HasKeystone;')
        ->and(substr_count($model, 'HasKeystone'))->toBe(2);
});

it('sets the guard driver to keystone', function () {
    $app = freshApp();

    $this->artisan('keystone:install')->assertSuccessful()->run();

    $auth = require "{$app}/config/auth.php";
    expect($auth['guards']['web']['driver'])->toBe('keystone');
});

it('names the session cookie with the __Host- prefix, which needs a secure cookie', function () {
    $app = freshApp();

    $this->artisan('keystone:install')->assertSuccessful()->run();
    $this->artisan('keystone:install')->assertSuccessful()->run();

    foreach (['.env', '.env.example'] as $file) {
        $env = file_get_contents("{$app}/{$file}");
        expect(substr_count($env, "\nSESSION_COOKIE=__Host-laravel-session\n"))->toBe(1)
            ->and(substr_count($env, "\nSESSION_SECURE_COOKIE=true\n"))->toBe(1)
            ->and(substr_count($env, "\nSESSION_DOMAIN=null\n"))->toBe(1)
            ->and(substr_count($env, "\nSESSION_PATH=/\n"))->toBe(1);
    }
});

it('leaves the app\'s cipher alone', function () {
    $app = freshApp();
    $before = file_get_contents("{$app}/config/app.php");

    $this->artisan('keystone:install')->assertSuccessful()->run();

    expect(file_get_contents("{$app}/config/app.php"))->toBe($before);
});

it('registers the Keystone testsuite once', function () {
    $app = freshApp();

    $this->artisan('keystone:install')->assertSuccessful()->run();
    $this->artisan('keystone:install')->assertSuccessful()->run();

    $phpunit = simplexml_load_file("{$app}/phpunit.xml");
    $suites = $phpunit->xpath('//testsuite[@name="Keystone"]');
    expect($suites)->toHaveCount(1)
        ->and(array_map('strval', $suites[0]->xpath('directory')))->toBe([
            'vendor/claudiodekker/keystone/app-tests',
            'vendor/claudiodekker/keystone-password/app-tests',
            'vendor/claudiodekker/keystone-inertia-vue/app-tests',
        ]);
});

it('maps the AppTests\' namespaces in the app\'s autoload-dev only', function () {
    $app = freshApp();

    $this->artisan('keystone:install')->assertSuccessful()->run();

    $manifest = json_decode(file_get_contents("{$app}/composer.json"), true);
    expect($manifest['autoload-dev']['psr-4'])->toBe([
        'Tests\\' => 'tests/',
        'ClaudioDekker\\Keystone\\AppTests\\' => 'vendor/claudiodekker/keystone/app-tests/',
        'ClaudioDekker\\Keystone\\Password\\AppTests\\' => 'vendor/claudiodekker/keystone-password/app-tests/',
        'ClaudioDekker\\Keystone\\InertiaVue\\AppTests\\' => 'vendor/claudiodekker/keystone-inertia-vue/app-tests/',
    ])->and($manifest['autoload']['psr-4'])->not->toHaveKey('ClaudioDekker\\Keystone\\AppTests\\');
});

it('sets up Inertia and Vue in a bare app, keeping its old Vite files as backups', function () {
    $app = freshApp();
    $viteConfig = file_get_contents("{$app}/vite.config.js");

    $this->artisan('keystone:install')->assertSuccessful()->run();

    expect("{$app}/vite.config.ts")->toBeFile()
        ->and("{$app}/resources/js/app.ts")->toBeFile()
        ->and("{$app}/resources/views/app.blade.php")->toBeFile()
        ->and("{$app}/app/Http/Middleware/HandleInertiaRequests.php")->toBeFile()
        ->and("{$app}/tsconfig.json")->toBeFile()
        ->and("{$app}/vite.config.js")->not->toBeFile()
        ->and("{$app}/resources/js/app.js")->not->toBeFile()
        ->and(file_get_contents("{$app}/vite.config.js.bak"))->toBe($viteConfig)
        ->and(file_get_contents("{$app}/resources/views/welcome.blade.php"))->toContain("@vite(['resources/css/app.css', 'resources/js/app.ts'])")
        ->and(file_get_contents("{$app}/bootstrap/app.php"))->toContain("\$middleware->web(append: [\n            \\App\\Http\\Middleware\\HandleInertiaRequests::class,\n        ]);");
});

it('leaves an app that already has Inertia and Vue set up as it is', function () {
    $app = freshApp();
    file_put_contents("{$app}/resources/js/app.ts", '// mine');
    $bootstrap = file_get_contents("{$app}/bootstrap/app.php");

    $this->artisan('keystone:install')->assertSuccessful()->run();

    expect("{$app}/vite.config.js")->toBeFile()
        ->and("{$app}/vite.config.js.bak")->not->toBeFile()
        ->and("{$app}/vite.config.ts")->not->toBeFile()
        ->and(file_get_contents("{$app}/resources/js/app.ts"))->toBe('// mine')
        ->and(file_get_contents("{$app}/resources/views/welcome.blade.php"))->toContain("'resources/js/app.js'")
        ->and(file_get_contents("{$app}/bootstrap/app.php"))->toBe($bootstrap);
});

it('adds the npm packages the pages need, keeping the versions the app pinned', function () {
    $app = freshApp();

    $this->artisan('keystone:install')->assertSuccessful()->run();

    $manifest = json_decode(file_get_contents("{$app}/package.json"), true);
    expect($manifest['devDependencies'])->toHaveKeys(['@inertiajs/vue3', '@laravel/vite-plugin-wayfinder', '@vitejs/plugin-vue', 'typescript', 'vue', 'vue-tsc'])
        ->and($manifest['devDependencies']['vite'])->toBe('^8.0.0')
        ->and($manifest['devDependencies']['laravel-vite-plugin'])->toBe('^3.1');
});

it('leaves the npm packages the app already has as dependencies alone', function () {
    $app = freshApp();
    $manifest = json_decode(file_get_contents("{$app}/package.json"), true);
    $manifest['dependencies'] = ['vue' => '^3.4.0'];
    file_put_contents("{$app}/package.json", json_encode($manifest));

    $this->artisan('keystone:install')->assertSuccessful()->run();

    $manifest = json_decode(file_get_contents("{$app}/package.json"), true);
    expect($manifest['dependencies'])->toBe(['vue' => '^3.4.0'])
        ->and($manifest['devDependencies'])->not->toHaveKey('vue');
});

it('installs and builds the npm packages', function () {
    $app = freshApp();

    $this->artisan('keystone:install')->assertSuccessful()->run();

    Process::assertRan(fn ($process) => $process->command === 'npm install' && $process->path === $app);
    Process::assertRan(fn ($process) => $process->command === 'npm run build' && $process->path === $app);
});

it('prints the npm step when it can\'t run it', function () {
    freshApp();
    Process::fake(fn (PendingProcess $process) => Process::result(exitCode: $process->command === 'npm install' ? 1 : 0));

    $this->artisan('keystone:install')
        ->expectsOutputToContain('npm install && npm run build')
        ->assertSuccessful()
        ->run();

    Process::assertDidntRun('npm run build');
});

it('refreshes the autoloader for the AppTests\' namespaces', function () {
    $app = freshApp();

    $this->artisan('keystone:install')->assertSuccessful()->run();

    Process::assertRan(fn ($process) => $process->command === 'composer dump-autoload' && $process->path === $app);
});

it('prints the middleware to add when it can\'t edit bootstrap/app.php', function () {
    $app = freshApp();
    file_put_contents("{$app}/bootstrap/app.php", '<?php // mine');

    $this->artisan('keystone:install')
        ->expectsOutputToContain('\App\Http\Middleware\HandleInertiaRequests::class,')
        ->assertSuccessful()
        ->run();

    expect(file_get_contents("{$app}/bootstrap/app.php"))->toBe('<?php // mine');
});

it('says what the user model needs when it can\'t edit it', function () {
    $app = freshApp();
    unlink("{$app}/app/Models/User.php");

    $this->artisan('keystone:install')
        ->expectsOutputToContain('Make App\Models\User implement ClaudioDekker\Keystone\KeystoneUser')
        ->assertSuccessful()
        ->run();
});

it('says to set the guard driver when it can\'t edit config/auth.php', function () {
    $app = freshApp();
    file_put_contents("{$app}/config/auth.php", '<?php return [];');

    $this->artisan('keystone:install')
        ->expectsOutputToContain("Set the web guard's driver to keystone")
        ->assertSuccessful()
        ->run();
});

it('prints the testsuite to add when it can\'t edit phpunit.xml', function () {
    $app = freshApp();
    unlink("{$app}/phpunit.xml");

    $this->artisan('keystone:install')
        ->expectsOutputToContain('<directory>vendor/claudiodekker/keystone/app-tests</directory>')
        ->assertSuccessful()
        ->run();

    expect("{$app}/phpunit.xml")->not->toBeFile();
});
