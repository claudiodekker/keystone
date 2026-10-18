<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The app's frontend, as keystone:install finds it.
 *
 * @internal
 */
class Frontend
{
    /**
     * Create the view of the frontend at the given base path.
     */
    public function __construct(protected string $basePath, protected Stubs $stubs, protected Filesystem $files)
    {
        //
    }

    /**
     * Determine if the app set its frontend up itself, rather than it being the skeleton's or Keystone's.
     */
    public function hasOwnSetup(): bool
    {
        return ! $this->isSkeletons() && ! $this->isKeystones();
    }

    /**
     * Determine if the frontend is still the one `laravel new` made, part way through Keystone's setup or not.
     */
    protected function isSkeletons(): bool
    {
        return ! $this->files->exists($this->path('resources/js/app.ts'))
            && $this->isAppJsSkeletons()
            && $this->viewsLoadingAppJs()->every(fn (string $view) => $view === 'welcome.blade.php');
    }

    /**
     * Determine if resources/js/app.ts is the one Keystone set up.
     */
    protected function isKeystones(): bool
    {
        $appTs = $this->path('resources/js/app.ts');

        return $this->files->exists($appTs)
            && $this->files->get($appTs) === $this->files->get($this->stubs->path('resources/js/app.ts'));
    }

    /**
     * Determine if resources/js/app.js is the skeleton's one line, or was moved aside to app.js.bak already.
     */
    protected function isAppJsSkeletons(): bool
    {
        $appJs = $this->path('resources/js/app.js');

        if (! $this->files->exists($appJs)) {
            return $this->files->exists($appJs.'.bak');
        }

        return trim($this->files->get($appJs)) === '//';
    }

    /**
     * Get the views that load resources/js/app.js, relative to resources/views.
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
            ->filter(fn (SplFileInfo $view) => str_contains($view->getContents(), 'resources/js/app.js'))
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
