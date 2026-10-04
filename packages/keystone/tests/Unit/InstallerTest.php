<?php

use ClaudioDekker\Keystone\Console\Installer;
use Illuminate\Filesystem\Filesystem;

function installerIn(array $files): Installer
{
    $path = sys_get_temp_dir().'/keystone-installer-'.bin2hex(random_bytes(6));
    $filesystem = new Filesystem;

    foreach ($files as $file => $contents) {
        $filesystem->ensureDirectoryExists(dirname("{$path}/{$file}"));
        $filesystem->put("{$path}/{$file}", $contents);
    }

    register_shutdown_function(fn () => $filesystem->deleteDirectory($path));

    return new Installer($filesystem, $path);
}

function parses(string $php): bool
{
    try {
        token_get_all($php, TOKEN_PARSE);
    } catch (ParseError) {
        return false;
    }

    return true;
}

it('adds KeystoneUser to the interfaces a user model already implements', function () {
    $installer = installerIn(['app/Models/User.php' => "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Foundation\\Auth\\User as Authenticatable;\n\nclass User extends Authenticatable implements MustVerifyEmail\n{\n    use Notifiable;\n}\n"]);

    expect($installer->makeKeystoneUser('App\Models\User'))->toBeTrue()
        ->and(file_get_contents($installer->path('app/Models/User.php')))
        ->toContain('class User extends Authenticatable implements MustVerifyEmail, KeystoneUser')
        ->toContain('use Notifiable, HasKeystone;');
});

it('adds the HasKeystone trait to a user model that uses no traits', function () {
    $installer = installerIn(['app/Models/User.php' => "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Foundation\\Auth\\User as Authenticatable;\n\nclass User extends Authenticatable\n{\n}\n"]);

    $installer->makeKeystoneUser('App\Models\User');

    expect(file_get_contents($installer->path('app/Models/User.php')))
        ->toContain("class User extends Authenticatable implements KeystoneUser\n{\n    use HasKeystone;\n");
});

it('leaves a user model it can\'t match unchanged', function () {
    $model = "<?php\n\nnamespace App\\Models;\n\nfinal class User extends \\Illuminate\\Foundation\\Auth\\User {}\n";
    $installer = installerIn(['app/Models/User.php' => $model]);

    expect($installer->makeKeystoneUser('App\Models\User'))->toBeFalse()
        ->and(file_get_contents($installer->path('app/Models/User.php')))->toBe($model);
});

it('makes a user model whose trait use has a block a Keystone user without breaking it', function () {
    $installer = installerIn(['app/Models/User.php' => "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Foundation\\Auth\\User as Authenticatable;\n\nclass User extends Authenticatable\n{\n    use HasFactory, Notifiable {\n        notify as protected baseNotify;\n    }\n}\n"]);

    expect($installer->makeKeystoneUser('App\Models\User'))->toBeTrue();

    $model = file_get_contents($installer->path('app/Models/User.php'));
    expect(parses($model))->toBeTrue()
        ->and($model)->toContain("implements KeystoneUser\n{\n    use HasKeystone;\n")
        ->toContain("use HasFactory, Notifiable {\n        notify as protected baseNotify;\n    }");
});

it('leaves a user model it can\'t edit safely unchanged', function (string $model) {
    $installer = installerIn(['app/Models/User.php' => $model]);

    expect($installer->makeKeystoneUser('App\Models\User'))->toBeFalse()
        ->and(file_get_contents($installer->path('app/Models/User.php')))->toBe($model);
})->with([
    'a fully qualified parent and no imports to put the new ones before' => "<?php\n\nnamespace App\\Models;\n\nclass User extends \\Illuminate\\Foundation\\Auth\\User\n{\n}\n",
    'interfaces over several lines' => "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Foundation\\Auth\\User as Authenticatable;\n\nclass User extends Authenticatable implements MustVerifyEmail,\n    Foo\n{\n}\n",
    'a comment after the interfaces' => "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Foundation\\Auth\\User as Authenticatable;\n\nclass User extends Authenticatable implements MustVerifyEmail // verified\n{\n}\n",
    'a file that doesn\'t parse' => "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Foundation\\Auth\\User as Authenticatable;\n\nclass User extends Authenticatable\n{\n    public function name(): string\n    {\n        return 'a'\n    }\n}\n",
]);

it('replaces a variable the environment already sets', function () {
    $installer = installerIn(['.env' => "APP_NAME=\"My App\"\nSESSION_COOKIE=old\n"]);

    $installer->setEnvironment(['SESSION_COOKIE' => 'new']);

    expect(file_get_contents($installer->path('.env')))->toBe("APP_NAME=\"My App\"\nSESSION_COOKIE=new\n");
});

it('reads a variable from the environment unquoted, or its default', function () {
    $installer = installerIn(['.env' => "APP_NAME=\"My App\"\n"]);

    expect($installer->environment('APP_NAME', 'laravel'))->toBe('My App')
        ->and($installer->environment('MISSING', 'laravel'))->toBe('laravel');
});

it('appends the middleware to the web group once', function () {
    $bootstrap = "<?php\n\nreturn Application::configure(basePath: dirname(__DIR__))\n    ->withMiddleware(function (Middleware \$middleware): void {\n        //\n    })\n    ->create();\n";
    $installer = installerIn(['bootstrap/app.php' => $bootstrap]);

    $installer->appendWebMiddleware('App\Http\Middleware\Probe');

    expect($installer->appendWebMiddleware('App\Http\Middleware\Probe'))->toBeTrue()
        ->and(substr_count(file_get_contents($installer->path('bootstrap/app.php')), 'Probe::class'))->toBe(1);
});

it('keeps the folder the app already maps a namespace to', function () {
    $installer = installerIn(['composer.json' => '{"autoload-dev": {"psr-4": {"Tests\\\\": "tests/", "Probe\\\\": "custom/"}}}']);

    $installer->mapAutoloadDev(['Probe\\' => 'vendor/probe/', 'Other\\' => 'vendor/other/']);

    $manifest = json_decode(file_get_contents($installer->path('composer.json')), true);
    expect($manifest['autoload-dev']['psr-4'])->toBe(['Tests\\' => 'tests/', 'Probe\\' => 'custom/', 'Other\\' => 'vendor/other/']);
});
