<?php

namespace ClaudioDekker\Keystone\Password;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The hash a subject without a usable password is checked against, so the check costs what a wrong password does.
 *
 * @internal
 */
class DummyHash
{
    /**
     * The cache key every dummy hash sits under, before the fingerprint of the hashing config.
     */
    protected const string KEY = 'keystone:password:dummy_hash';

    /**
     * Create the dummy hash.
     */
    public function __construct(protected Repository $config)
    {
        //
    }

    /**
     * Get the dummy hash, made on the spot when the cache holds none or cannot answer.
     */
    public function get(): string
    {
        return rescue(
            fn () => Cache::rememberForever($this->key(), fn () => $this->make()),
            fn () => $this->make(),
        );
    }

    /**
     * Make a hash of a random password with the app's driver and cost.
     */
    protected function make(): string
    {
        return Hash::make(Str::random(40));
    }

    /**
     * Get the cache key for the current hashing config, so a new driver, cost or option reads another entry.
     */
    protected function key(): string
    {
        $fingerprint = hash('sha256', json_encode($this->config->array('hashing'), JSON_THROW_ON_ERROR));

        return static::KEY.':'.$fingerprint;
    }
}
