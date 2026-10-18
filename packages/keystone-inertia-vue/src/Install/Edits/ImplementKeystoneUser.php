<?php

namespace ClaudioDekker\Keystone\InertiaVue\Install\Edits;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\InertiaVue\Install\Edit;

/**
 * Make the provider's user model implement KeystoneUser.
 *
 * @internal
 */
class ImplementKeystoneUser extends Edit
{
    /**
     * Create the edit of the model class and the file that holds it.
     */
    public function __construct(protected ?string $class, protected ?string $path)
    {
        //
    }

    /**
     * Get the model's file, relative to the app's base path, or null when it can't be found.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * Get the file's contents with KeystoneUser implemented and imported.
     */
    public function apply(string $contents): ?string
    {
        if ($this->class === null) {
            return null;
        }

        $pattern = '/^class '.class_basename($this->class).' extends \w+(?: implements (?<interfaces>[\w\\\\, ]+))?$/m';

        if (preg_match($pattern, $contents, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        [$line, $offset] = $match[0];
        $interfaces = isset($match['interfaces']) ? explode(', ', $match['interfaces'][0]) : [];

        if (in_array('KeystoneUser', $interfaces, true)) {
            return $this->import($contents, KeystoneUser::class);
        }

        $implemented = $interfaces === [] ? $line.' implements KeystoneUser' : $line.', KeystoneUser';
        $declared = substr_replace($contents, $implemented, $offset, strlen($line));

        return $this->import($declared, KeystoneUser::class);
    }

    /**
     * Get the change to make by hand.
     */
    public function instructions(): string
    {
        $model = $this->class ?? 'your user model';

        return 'Add implements '.KeystoneUser::class." to {$model}.";
    }
}
