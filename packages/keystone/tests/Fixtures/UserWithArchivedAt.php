<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

class UserWithArchivedAt extends User
{
    public const DELETED_AT = 'archived_at';

    protected $table = 'users';
}
