<?php

namespace ClaudioDekker\Keystone\Password\AppTests\Support;

use Illuminate\Contracts\Hashing\Hasher;
use RuntimeException;
use SensitiveParameter;

/**
 * A driver that checks as the real one does but fails every hash it is asked to make.
 *
 * @internal
 */
class FailingHasher implements Hasher
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
     * Fail to hash the value.
     *
     * @param  array<string, mixed>  $options
     */
    public function make(#[SensitiveParameter] $value, array $options = []): string
    {
        throw new RuntimeException('The hasher failed to hash.');
    }

    /**
     * Check the value with the real driver.
     *
     * @param  array<string, mixed>  $options
     */
    public function check(#[SensitiveParameter] $value, $hashedValue, array $options = []): bool
    {
        return $this->hasher->check($value, $hashedValue, $options);
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
