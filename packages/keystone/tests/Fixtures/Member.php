<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures;

use ClaudioDekker\Keystone\HasKeystone;
use ClaudioDekker\Keystone\KeystoneUser;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Member extends Authenticatable implements KeystoneUser
{
    use HasKeystone;

    protected $table = 'members';

    protected $primaryKey = 'member_id';

    protected $guarded = [];
}
