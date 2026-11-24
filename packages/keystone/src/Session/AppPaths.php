<?php

namespace ClaudioDekker\Keystone\Session;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Str;

/**
 * @internal
 */
class AppPaths
{
    /**
     * Create the app's paths.
     */
    public function __construct(
        protected UrlGenerator $urls,
    ) {
        //
    }

    /**
     * Get the URL as a path on the app, or null when it isn't one.
     */
    public function toPath(string $url): ?string
    {
        $path = Str::chopStart($url, $this->urls->to('/'));

        if (! $this->isPath($path)) {
            return null;
        }

        return $path;
    }

    /**
     * Determine if the URL is a path on this app: one leading slash, and no backslash or control character.
     */
    protected function isPath(string $url): bool
    {
        return str_starts_with($url, '/')
            && ! str_starts_with($url, '//')
            && ! str_contains($url, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $url) === 0;
    }
}
