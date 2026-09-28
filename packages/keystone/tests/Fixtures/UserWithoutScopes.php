<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

class UserWithoutScopes extends User
{
    protected $table = 'users';

    public static function bootSoftDeletes(): void
    {
        //
    }
}
