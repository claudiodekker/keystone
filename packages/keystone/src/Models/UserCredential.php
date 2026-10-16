<?php

namespace ClaudioDekker\Keystone\Models;

use ClaudioDekker\Keystone\Auth\KeystoneUser;
use ClaudioDekker\Keystone\Database\Factories\UserCredentialFactory;
use ClaudioDekker\Keystone\Keystone;
use ClaudioDekker\Keystone\Methods\Credential;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A credential an account holds, of any type.
 *
 * @api
 */
#[UseFactory(UserCredentialFactory::class)]
class UserCredential extends Model
{
    /** @use HasFactory<UserCredentialFactory> */
    use HasFactory;

    /**
     * Get the account that holds the credential.
     *
     * @return BelongsTo<Model&KeystoneUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(Keystone::userModel(), 'user_id');
    }

    /**
     * Get the credential as a type sees it.
     */
    public function toCredential(): Credential
    {
        return new Credential($this->getKey(), $this->secret);
    }
}
