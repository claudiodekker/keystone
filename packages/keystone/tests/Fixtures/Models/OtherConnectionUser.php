<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Models;

class OtherConnectionUser extends User
{
    /**
     * The connection the model reads and writes on.
     *
     * @var string|null
     */
    protected $connection = 'secondary';
}
