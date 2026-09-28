<?php

namespace ClaudioDekker\Keystone;

/**
 * @internal
 */
class IntendedUrl
{
    /**
     * Keep the intended URL only as a same-origin relative path, else the root.
     */
    public static function sanitize(?string $url, string $appUrl): string
    {
        if ($url === null || preg_match('/[\x00-\x1F\x7F\\\\]/', rawurldecode($url))) {
            return '/';
        }

        if (static::origin($url) === static::origin($appUrl)) {
            $url = substr($url, strlen((string) static::origin($url))) ?: '/';
        }

        return static::isRelativePath($url) ? $url : '/';
    }

    /**
     * Get the scheme, host and port of an absolute URL.
     */
    protected static function origin(string $url): ?string
    {
        $parts = parse_url($url);

        if (! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower("{$parts['scheme']}://{$parts['host']}{$port}");
    }

    /**
     * Determine if the URL is a path with one leading slash.
     */
    protected static function isRelativePath(string $url): bool
    {
        return str_starts_with($url, '/') && ! str_starts_with(rawurldecode($url), '//');
    }
}
