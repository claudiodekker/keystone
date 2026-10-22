<?php

namespace ClaudioDekker\Keystone\Tests\Fixtures\Models;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Tests\Fixtures\Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The fixture app's user model, with its own table and key name, so every AppTest in core CI runs on both.
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable implements KeystoneUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /**
     * The table the model reads.
     *
     * @var string
     */
    protected $table = 'members';

    /**
     * The model's primary key.
     *
     * @var string
     */
    protected $primaryKey = 'member_id';

    /**
     * Get the attributes that should be cast, as the skeleton's user model casts them.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }
}
