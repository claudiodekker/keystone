<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

class UserWithUuidIdentifier extends User
{
    protected $table = 'users';

    public function getAuthIdentifierName(): string
    {
        return 'uuid';
    }
}
