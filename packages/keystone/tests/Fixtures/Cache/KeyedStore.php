<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Cache;

use Illuminate\Cache\ArrayStore;

/**
 * An in-memory cache store that tells which keys it holds.
 */
class KeyedStore extends ArrayStore
{
    /**
     * Get every key the store holds.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->storage));
    }
}
