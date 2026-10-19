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
     * The stubs that replace the app's file until it names what the stub adds, by that name.
     *
     * @var array<string, string>
     */
    protected const array REPLACEMENTS = [
        'app/Models/User.php' => 'KeystoneUser',
        'bootstrap/app.php' => 'HandleInertiaRequests',
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
            ->reject(fn (string $path) => array_key_exists($path, static::REPLACEMENTS))
            ->filter(fn (string $path) => $this->isCopiedFor($path, $partials))
            ->values()
            ->all();
    }

    /**
     * Get the stubs that replace the app's file until it names what the stub adds, by that name.
     *
     * @return array<string, string>
     */
    public function replacements(): array
    {
        return static::REPLACEMENTS;
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
