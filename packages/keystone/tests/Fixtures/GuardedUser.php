<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

class GuardedUser extends User
{
    protected $table = 'users';

    protected $fillable = ['name'];

    protected $guarded = ['*'];
}
