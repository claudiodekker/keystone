<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The state of the app's frontend setup.
 *
 * @internal
 */
class Frontend
{
    /**
     * The skeleton's script, relative to the app's base path.
     */
    public const string APP_JS = 'resources/js/app.js';

    /**
     * The skeleton's Vite entry files, which the setup moves aside.
     *
     * @var list<string>
     */
    public const array VITE_ENTRIES = ['vite.config.js', self::APP_JS];

    /**
     * The skeleton's welcome page, relative to the app's base path.
     */
    public const string WELCOME_VIEW = 'resources/views/welcome.blade.php';

    /**
     * Create the view of the frontend at the given base path.
     */
    public function __construct(protected string $basePath, protected Stubs $stubs, protected Filesystem $files)
    {
        //
    }

    /**
     * Determine if the app set its frontend up itself.
     */
    public function hasOwnSetup(): bool
    {
        return ! $this->isBare() && ! $this->isSetUpByKeystone();
    }

    /**
     * Determine if the app has the skeleton's welcome page.
     */
    public function hasWelcomeView(): bool
    {
        return $this->files->exists($this->path(static::WELCOME_VIEW));
    }

    /**
     * Determine if the frontend is still the one `laravel new` made.
     */
    protected function isBare(): bool
    {
        return ! $this->files->exists($this->path('resources/js/app.ts'))
            && $this->hasSkeletonAppJs()
            && $this->viewsLoadingAppJs()->every(fn (string $view) => 'resources/views/'.$view === static::WELCOME_VIEW);
    }

    /**
     * Determine if resources/js/app.ts is the one Keystone set up.
     */
    protected function isSetUpByKeystone(): bool
    {
        $appTs = $this->path('resources/js/app.ts');

        return $this->files->exists($appTs)
            && $this->files->get($appTs) === $this->files->get($this->stubs->path('resources/js/app.ts'));
    }

    /**
     * Determine if the app never replaced the skeleton's script.
     */
    protected function hasSkeletonAppJs(): bool
    {
        $appJs = $this->path(static::APP_JS);

        if (! $this->files->exists($appJs)) {
            return $this->files->exists($appJs.'.bak');
        }

        return trim($this->files->get($appJs)) === '//';
    }

    /**
     * Get the views that load the skeleton's script, relative to resources/views.
     *
     * @return Collection<int, string>
     */
    protected function viewsLoadingAppJs(): Collection
    {
        $views = $this->path('resources/views');

        if (! $this->files->isDirectory($views)) {
            return collect();
        }

        return collect($this->files->allFiles($views))
            ->filter(fn (SplFileInfo $view) => str_contains($view->getContents(), static::APP_JS))
            ->map(fn (SplFileInfo $view) => $view->getRelativePathname())
            ->values();
    }

    /**
     * Get the path of a file in the app.
     */
    protected function path(string $path): string
    {
        return $this->basePath.'/'.$path;
    }
}
