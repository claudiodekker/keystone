<?php

namespace ClaudioDekker\Keystone\Password\AppTests\Support;

use Illuminate\Contracts\Hashing\Hasher;
use SensitiveParameter;

/**
 * @internal
 */
class RefusingHasher implements Hasher
{
    /**
     * Create the hasher around the real driver.
     */
    public function __construct(protected Hasher $hasher)
    {
        //
    }

    /**
     * Get the real driver's information about the hash.
     *
     * @return array<string, mixed>
     */
    public function info($hashedValue): array
    {
        return $this->hasher->info($hashedValue);
    }

    /**
     * Hash the value with the real driver.
     *
     * @param  array<string, mixed>  $options
     */
    public function make(#[SensitiveParameter] $value, array $options = []): string
    {
        return $this->hasher->make($value, $options);
    }

    /**
     * Refuse the value, whatever the hash.
     *
     * @param  array<string, mixed>  $options
     */
    public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        return false;
    }

    /**
     * Determine if the real driver wants the hash made again.
     *
     * @param  array<string, mixed>  $options
     */
    public function needsRehash($hashedValue, array $options = []): bool
    {
        return $this->hasher->needsRehash($hashedValue, $options);
    }
}
