<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The adapter's stubs, by their path in the app.
 *
 * @internal
 */
class Stubs
{
    /**
     * The stubs that set up Inertia and Vue, which are not copied with the rest.
     *
     * @var list<string>
     */
    protected const array INERTIA_SETUP = [
        'app/Http/Middleware/HandleInertiaRequests.php',
        'resources/js/app.ts',
        'resources/views/app.blade.php',
        'tsconfig.json',
        'vite.config.ts',
    ];

    /**
     * Create the listing of the stubs directory.
     */
    public function __construct(protected string $directory = __DIR__.'/../../stubs', protected Filesystem $files = new Filesystem)
    {
        //
    }

    /**
     * Get the path in the app of each stub to copy, with a type partial only for the named credential types.
     *
     * @param  list<string>  $types
     * @return array<int, string>
     */
    public function for(array $types): array
    {
        $partials = collect($types)->map(fn (string $type) => Str::studly($type));

        return $this->paths()
            ->reject(fn (string $path) => in_array($path, static::INERTIA_SETUP, true))
            ->filter(fn (string $path) => $this->isCopiedFor($path, $partials))
            ->values()
            ->all();
    }

    /**
     * Get the stub's absolute path.
     */
    public function path(string $path): string
    {
        return $this->directory.'/'.$path;
    }

    /**
     * Determine if the stub is copied for the given type partials.
     *
     * @param  Collection<int, string>  $partials
     */
    protected function isCopiedFor(string $path, Collection $partials): bool
    {
        if (preg_match('#^resources/js/partials/([^/]+)\.vue$#', $path, $match) !== 1) {
            return true;
        }

        return $partials->contains($match[1]);
    }

    /**
     * Get the path in the app of every stub.
     *
     * @return Collection<int, string>
     */
    protected function paths(): Collection
    {
        return collect($this->files->allFiles($this->directory))
            ->map(fn (SplFileInfo $file) => $file->getRelativePathname());
    }
}
