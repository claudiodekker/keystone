<?php

namespace ClaudioDekker\Keystone;

use Illuminate\Http\Request;

/**
 * @internal
 */
class IntendedUrl
{
    /**
     * Get where a request the sudo gate refused goes once sudo is granted: the page it asked for, or the page it came from, since a mutation is never replayed.
     */
    public static function afterSudo(Request $request, string $appUrl): string
    {
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return static::sanitize($request->fullUrl(), $appUrl);
        }

        return static::sanitize($request->headers->get('Referer'), $appUrl);
    }

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
