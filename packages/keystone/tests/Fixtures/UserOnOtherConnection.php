<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

class UserOnOtherConnection extends User
{
    protected $connection = 'other';

    protected $table = 'users';
}
