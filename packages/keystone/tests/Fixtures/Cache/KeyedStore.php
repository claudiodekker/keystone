<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Cache;

use Illuminate\Cache\ArrayStore;

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
