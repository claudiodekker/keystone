<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Models;

/**
 * The fixture app's user model, kept on a connection other than the default.
 */
class OtherConnectionUser extends User
{
    /**
     * The connection the model reads and writes on.
     *
     * @var string|null
     */
    protected $connection = 'secondary';
}
