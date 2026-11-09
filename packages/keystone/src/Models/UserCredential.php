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
use SensitiveParameter;

/**
 * @api
 */
#[UseFactory(UserCredentialFactory::class)]
class UserCredential extends Model
{
    /** @use HasFactory<UserCredentialFactory> */
    use HasFactory;

    /**
     * The attributes kept out of the model's array form.
     *
     * @var list<string>
     */
    protected $hidden = ['secret'];

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

    /**
     * Get the secret as the column stores it, encrypted with a fresh IV.
     */
    public function encryptSecret(#[SensitiveParameter] string $secret): string
    {
        return $this->castAttributeAsEncryptedString('secret', $secret);
    }

    /**
     * Get the attribute casts: core always encrypts the secret, whatever the type hashed into it.
     *
     * @return array{secret: 'encrypted'}
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
        ];
    }
}
