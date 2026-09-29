<?php

namespace ClaudioDekker\Keystone\InertiaVue\Console;

use ClaudioDekker\Keystone\Console\Installer;
use ClaudioDekker\Keystone\Methods\CredentialType;
use ClaudioDekker\Keystone\Methods\CredentialTypes;
use ClaudioDekker\Keystone\Methods\Surface;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal
 */
#[AsCommand(name: 'keystone:install')]
class InstallCommand extends Command
{
    /**
     * The stubs that set up Inertia and Vue, copied only into an app that hasn't set them up yet.
     */
    protected const array BOOTSTRAP = [
        'app/Http/Middleware/HandleInertiaRequests.php',
        'resources/js/app.ts',
        'resources/views/app.blade.php',
        'tsconfig.json',
        'vite.config.ts',
    ];

    /**
     * The folder the stubs of credential types' partials live in.
     */
    protected const string PARTIALS = 'resources/js/partials/';

    /**
     * The npm packages the pages need, with the versions they're added at when the app has none.
     */
    protected const array NPM_PACKAGES = [
        '@inertiajs/vue3' => '^3.7.1',
        '@laravel/vite-plugin-wayfinder' => '^0.1.10',
        '@tailwindcss/vite' => '^4.3.3',
        '@vitejs/plugin-vue' => '^6.0.9',
        'laravel-vite-plugin' => '^3.2.0',
        'tailwindcss' => '^4.3.3',
        'typescript' => '^6.0.3',
        'vite' => '^8.3.1',
        'vue' => '^3.5.43',
        'vue-tsc' => '^3.3.11',
    ];

    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'keystone:install {--force : Overwrite the files the app already has}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Install Keystone's Inertia and Vue pages into the app";

    /**
     * Install Keystone into the app.
     */
    public function handle(Filesystem $files, CredentialTypes $types): int
    {
        $installer = new Installer($files, $this->laravel->basePath());

        if (($refusal = $installer->refusal()) !== null) {
            $this->components->error($refusal);

            return self::FAILURE;
        }

        $bare = ! $installer->has('resources/js/app.ts');

        if ($bare) {
            $installer->backUp('vite.config.js');
            $installer->backUp('resources/js/app.js');
        }

        $partials = array_map(fn (CredentialType $type) => self::PARTIALS.Str::studly($type->name()).'.vue', $types->serving(Surface::SIGN_IN));

        $copies = $installer->copyStubs(dirname(__DIR__, 2).'/stubs', (bool) $this->option('force'), fn (string $path) => match (true) {
            in_array($path, self::BOOTSTRAP, true) => $bare,
            dirname($path).'/' === self::PARTIALS => in_array($path, $partials, true),
            default => true,
        });

        $this->reportCopies($copies);
        $this->wireIntoApp($installer, $bare);
        $this->installNpmPackages($installer);

        return self::SUCCESS;
    }

    /**
     * List the stubs that were copied, and the ones skipped because the app already has them.
     *
     * @param  array{copied: list<string>, skipped: list<string>}  $copies
     */
    protected function reportCopies(array $copies): void
    {
        if ($copies['copied'] !== []) {
            $this->components->info('Copied into the app:');
            $this->components->bulletList($copies['copied']);
        }

        if ($copies['skipped'] !== []) {
            $this->components->warn('Skipped, because the app already has them (--force overwrites):');
            $this->components->bulletList($copies['skipped']);
        }
    }

    /**
     * Wire the copied files, the user model, the guard, the session cookie and the AppTests into the app.
     */
    protected function wireIntoApp(Installer $installer, bool $bare): void
    {
        $installer->requireRouteFile('keystone.php');

        if ($bare && ! $installer->appendWebMiddleware('App\Http\Middleware\HandleInertiaRequests')) {
            $this->components->warn('Add HandleInertiaRequests to the web middleware in bootstrap/app.php:');
            $this->line("    \$middleware->web(append: [\n        \\App\\Http\\Middleware\\HandleInertiaRequests::class,\n    ]);");
        }

        if ($bare) {
            $installer->replaceIn('resources/views/welcome.blade.php', search: "'resources/js/app.js'", replace: "'resources/js/app.ts'");
        }

        $model = config()->string('auth.providers.users.model');

        if (! $installer->makeKeystoneUser($model)) {
            $this->components->warn("Make {$model} implement ClaudioDekker\\Keystone\\KeystoneUser and use ClaudioDekker\\Keystone\\HasKeystone.");
        }

        if (! $installer->useKeystoneGuard()) {
            $this->components->warn("Set the web guard's driver to keystone in config/auth.php.");
        }

        $appName = $installer->environment(name: 'APP_NAME', default: 'laravel');

        $installer->setEnvironment([
            'SESSION_COOKIE' => '__Host-'.Str::slug($appName).'-session',
            'SESSION_SECURE_COOKIE' => 'true',
            'SESSION_DOMAIN' => 'null',
            'SESSION_PATH' => '/',
        ]);

        $appTests = $installer->appTests();
        $directories = array_map(fn (string $directory) => rtrim($directory, '/'), array_values($appTests));

        if (! $installer->registerTestsuite($directories)) {
            $this->components->warn('Add the Keystone testsuite to phpunit.xml:');
            $this->line("    <testsuite name=\"Keystone\">\n".implode('', array_map(fn (string $directory) => "        <directory>{$directory}</directory>\n", $directories)).'    </testsuite>');
        }

        $installer->mapAutoloadDev($appTests);
        $this->runOrPrint($installer, ['composer dump-autoload']);
    }

    /**
     * Add the npm packages the pages need, then install them and build the pages.
     */
    protected function installNpmPackages(Installer $installer): void
    {
        $installer->addNpmPackages(self::NPM_PACKAGES);

        $this->runOrPrint($installer, ['npm install', 'npm run build']);
    }

    /**
     * Run the commands in the app in turn, printing the ones left to run when one fails.
     *
     * @param  list<string>  $commands
     */
    protected function runOrPrint(Installer $installer, array $commands): void
    {
        foreach ($commands as $command) {
            $result = Process::path($installer->path())->forever()->run($command, fn (string $type, string $output) => $this->output->write($output));

            if ($result->failed()) {
                $this->components->warn('Finish the install by running: '.implode(' && ', $commands));

                return;
            }
        }
    }
}
