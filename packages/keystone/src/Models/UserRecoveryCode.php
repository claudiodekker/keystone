<?php

namespace ClaudioDekker\Keystone\Models;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Database\Factories\UserRecoveryCodeFactory;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Credential;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @api
 */
#[UseFactory(UserRecoveryCodeFactory::class)]
class UserRecoveryCode extends Model
{
    /** @use HasFactory<UserRecoveryCodeFactory> */
    use HasFactory;

    /**
     * The attributes kept out of the model's array form.
     *
     * @var list<string>
     */
    protected $hidden = ['digest'];

    /**
     * Get the account that holds the code.
     *
     * @return BelongsTo<Model&KeystoneUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Keystone::userModel(), 'user_id');
    }

    /**
     * Get the code as the recovery code type sees it.
     */
    public function toCredential(): Credential
    {
        return new Credential($this->getKey(), $this->digest);
    }
}
