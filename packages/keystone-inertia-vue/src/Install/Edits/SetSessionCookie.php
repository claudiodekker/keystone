<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install\Edits;

use ClaudioDekker\Keystone\InertiaVue\Install\Edit;
use Illuminate\Support\Str;

/**
 * @internal
 */
class SetSessionCookie extends Edit
{
    /**
     * Create the edit of the given env file, with the cookie named after the app.
     */
    public function __construct(protected string $path, protected string $appName)
    {
        //
    }

    /**
     * Get the file the edit changes, relative to the app's base path.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Get the file's contents with the cookie settings in the session block.
     */
    public function apply(string $contents): ?string
    {
        $lines = explode("\n", $contents);

        if (! in_array('SESSION_DOMAIN=null', $lines, true)) {
            return null;
        }

        foreach ($this->settings() as $setting) {
            [$key] = explode('=', $setting);
            $current = array_find($lines, fn (string $line) => str_starts_with($line, "{$key}="));

            if ($current === $setting) {
                continue;
            }

            if ($current !== null) {
                return null;
            }

            array_splice($lines, $this->endOfSessionBlock($lines), 0, [$setting]);
        }

        return implode("\n", $lines);
    }

    /**
     * Get the change to make by hand.
     */
    public function instructions(): string
    {
        return 'Set '.implode(' and ', $this->settings())." in {$this->path}, with SESSION_DOMAIN=null.";
    }

    /**
     * Get the settings the file needs, as lines.
     *
     * @return list<string>
     */
    protected function settings(): array
    {
        $cookie = '__Host-'.Str::slug($this->appName).'-session';

        return ["SESSION_COOKIE={$cookie}", 'SESSION_SECURE_COOKIE=true'];
    }

    /**
     * Get the index of the line after the last session setting.
     *
     * @param  list<string>  $lines
     */
    protected function endOfSessionBlock(array $lines): int
    {
        $end = 0;

        foreach ($lines as $index => $line) {
            if (str_starts_with($line, 'SESSION_')) {
                $end = $index + 1;
            }
        }

        return $end;
    }
}
