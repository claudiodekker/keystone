<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\HasKeystone;
use ClaudioDekker\Keystone\KeystoneUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements KeystoneUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasKeystone;

    protected $guarded = [];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
