<?php

namespace Workbench\App\Models;

use ClaudioDekker\Keystone\HasKeystone;
use ClaudioDekker\Keystone\KeystoneUser;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements KeystoneUser
{
    use HasKeystone;

    protected $fillable = ['name'];
}
