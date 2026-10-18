<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install;

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The adapter's stubs, by their path in the app.
 *
 * @internal
 */
class Stubs
{
    /**
     * Create the listing of the stubs directory.
     */
    public function __construct(protected string $directory = __DIR__.'/../../stubs', protected Filesystem $files = new Filesystem)
    {
        //
    }

    /**
     * Get the path of each stub in the app.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_values(array_map(
            fn (SplFileInfo $file) => $file->getRelativePathname(),
            $this->files->allFiles($this->directory),
        ));
    }

    /**
     * Get the stub's absolute path.
     */
    public function path(string $path): string
    {
        return $this->directory.'/'.$path;
    }
}
